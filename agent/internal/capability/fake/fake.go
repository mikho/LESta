// Package fake implements a stateless, no-disk-I/O Capability that runs
// identically on any OS, deliberately reusing FakeProvisioner.php's exact
// digest and generation-id formula (sha256:hash('sha256','fake:'.$idempotencyKey),
// 'fake-'.$idempotencyKey) so the same fake exists, recognizably, in both
// languages.
//
// FakeProvisioner.php itself is a dumb stub: it always returns Applied,
// regardless of operation or payload, to exercise Laravel's outbox/dispatch
// mechanism. This Go FakeCapability additionally has to pass the exact same
// shared contract suite as the real NginxCapability (internal/contract), which
// requires it to actually reject an invalid domain, report unknown_resource for
// an operation on a never-created resource, and dedupe a duplicate idempotency
// key, none of which the PHP stub does at all. Those behaviors are this
// package's own reasoned extension on top of the mirrored formula, not a literal
// requirement from FakeProvisioner.php — see hostnamePattern below and the
// per-resource bookkeeping in FakeCapability.
package fake

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"regexp"
	"sync"
	"time"

	"github.com/mikho/LESta/agent/internal/idempotency"
	"github.com/mikho/LESta/agent/internal/protocol"
)

// hostnamePattern duplicates internal/capability/nginx's own hostname
// validation regex rather than importing that package. Fake deliberately
// doesn't depend on the real capability it stands in for; the regex is a
// single line, and keeping fake fully self-contained matters more here than
// avoiding this small duplication.
var hostnamePattern = regexp.MustCompile(`^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$`)

// payload is the minimal shape FakeCapability inspects. It ignores every field
// except domain/aliases (for the invalid-domain contract case) and
// web_template (for the unsupported-web-template contract case): the digest
// and generation ID it returns never depend on payload content, only on the
// envelope's own idempotency_key, matching FakeProvisioner.php exactly.
type payload struct {
	Domain      string   `json:"domain"`
	Aliases     []string `json:"aliases"`
	WebTemplate string   `json:"web_template"`
}

// resourceState is the minimal per-resource memory FakeCapability keeps, purely
// to support the precondition checks (create requires no prior state; update/
// suspend/unsuspend/delete require one) and observe/drift reporting the shared
// contract suite exercises against both capabilities. It is in-memory only: see
// internal/idempotency's own package doc for why disk persistence isn't needed,
// or wanted, here.
type resourceState struct {
	digest       string
	generationID string
	suspended    bool
	// preSuspendDigest/preSuspendGenerationID remember the digest/generation ID
	// that was active immediately before the most recent suspend, so unsuspend
	// can restore them exactly. FakeProvisioner.php's formula is keyed purely by
	// idempotency_key, which by construction differs on every distinct
	// operation; without this restoration, "create then suspend then unsuspend
	// ends with the original digest restored" (the contract suite's own,
	// literal requirement) could never hold for the fake, only for the real
	// nginx capability, whose digest is keyed by rendered content instead. This
	// is the fake's own structural way of modeling the same restoration nginx
	// gets for free from content-addressed digests.
	preSuspendDigest       string
	preSuspendGenerationID string
	// deleted mirrors NginxCapability's own generation history: delete does not
	// erase this resource's memory, it records that the current, correctly
	// achieved state is "gone" (matching a manifest recorded for an
	// os.Remove'd live fragment). A resourceID this node has generation history
	// for, deleted or not, is never unknown_resource; only a resourceID this
	// node has never seen at all is.
	deleted bool
}

// Capability implements protocol.Capability with no dependency on any real
// service.
type Capability struct {
	mu        sync.Mutex
	resources map[string]resourceState
	receipts  *idempotency.Store
}

// New returns an empty, stateless-w.r.t.-the-OS FakeCapability.
func New() *Capability {
	return &Capability{
		resources: make(map[string]resourceState),
		receipts:  idempotency.New(),
	}
}

// Apply implements protocol.Capability.
func (c *Capability) Apply(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
	_, cancel := context.WithDeadline(ctx, op.Deadline)
	defer cancel()

	if op.Operation != protocol.OperationObserve {
		if prior, ok := c.receipts.Lookup(op.IdempotencyKey); ok {
			if prior.Status == protocol.StatusApplied || prior.Status == protocol.StatusAlreadyApplied {
				prior.Status = protocol.StatusAlreadyApplied
			}

			return prior, nil
		}
	}

	result, err := c.apply(op)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if op.Operation != protocol.OperationObserve {
		c.receipts.Record(op.IdempotencyKey, result)
	}

	return result, nil
}

func (c *Capability) apply(op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
	c.mu.Lock()
	defer c.mu.Unlock()

	var p payload
	if len(op.Payload) > 0 {
		if err := decodePayload(op.Payload, &p); err != nil {
			return protocol.ResultEnvelope{}, fmt.Errorf("decoding fake payload: %w", err)
		}
	}

	if p.Domain != "" && !hostnamePattern.MatchString(p.Domain) {
		return c.rejected(op, "invalid_domain", "domain is not a valid hostname", "domain"), nil
	}

	for i, alias := range p.Aliases {
		if !hostnamePattern.MatchString(alias) {
			return c.rejected(op, "invalid_domain", "alias is not a valid hostname", fmt.Sprintf("aliases[%d]", i)), nil
		}
	}

	if p.WebTemplate != "" && p.WebTemplate != "default" {
		return c.rejected(op, "unsupported_web_template", fmt.Sprintf("web_template %q is not supported; only \"default\" is available this phase", p.WebTemplate), "web_template"), nil
	}

	state, hasState := c.resources[op.ResourceID]

	switch op.Operation {
	case protocol.OperationCreate:
		if hasState {
			return c.rejected(op, "resource_already_exists", "a generation already exists for this resource; use update instead of create", ""), nil
		}

		return c.applyFresh(op), nil

	case protocol.OperationUpdate:
		if !hasState {
			return c.rejected(op, "unknown_resource", "no prior generation exists for this resource on this node", ""), nil
		}

		return c.applyFresh(op), nil

	case protocol.OperationSuspend:
		if !hasState {
			return c.rejected(op, "unknown_resource", "no prior generation exists for this resource on this node", ""), nil
		}

		digest, generationID := formula(op.IdempotencyKey)
		c.resources[op.ResourceID] = resourceState{
			digest:                 digest,
			generationID:           generationID,
			suspended:              true,
			preSuspendDigest:       state.digest,
			preSuspendGenerationID: state.generationID,
		}

		return c.applied(op, digest, generationID), nil

	case protocol.OperationUnsuspend:
		if !hasState {
			return c.rejected(op, "unknown_resource", "no prior generation exists for this resource on this node", ""), nil
		}

		digest, generationID := state.preSuspendDigest, state.preSuspendGenerationID
		if digest == "" {
			// Never suspended (or state predates any suspend): unsuspend of an
			// already-active resource is a fresh apply of its own.
			return c.applyFresh(op), nil
		}

		c.resources[op.ResourceID] = resourceState{digest: digest, generationID: generationID, suspended: false}

		return c.applied(op, digest, generationID), nil

	case protocol.OperationDelete:
		if !hasState {
			return c.rejected(op, "unknown_resource", "no prior generation exists for this resource on this node", ""), nil
		}

		digest, generationID := formula(op.IdempotencyKey)
		c.resources[op.ResourceID] = resourceState{digest: digest, generationID: generationID, deleted: true}

		return c.applied(op, digest, generationID), nil

	case protocol.OperationObserve:
		if !hasState {
			return c.rejected(op, "unknown_resource", "no generation history exists for this resource on this node", ""), nil
		}

		// The fake has no independent notion of the real world drifting out
		// from under it (there is no real filesystem it renders to), so
		// observe simply echoes its own last-recorded state: always applied
		// for a known resource.
		return c.buildResult(op, protocol.StatusApplied, state.digest, state.generationID, nil), nil

	default:
		return c.rejected(op, "unsupported_operation", fmt.Sprintf("operation %q is not supported", op.Operation), ""), nil
	}
}

func (c *Capability) applyFresh(op protocol.OperationEnvelope) protocol.ResultEnvelope {
	digest, generationID := formula(op.IdempotencyKey)
	c.resources[op.ResourceID] = resourceState{digest: digest, generationID: generationID}

	return c.applied(op, digest, generationID)
}

func (c *Capability) applied(op protocol.OperationEnvelope, digest, generationID string) protocol.ResultEnvelope {
	return c.buildResult(op, protocol.StatusApplied, digest, generationID, nil)
}

func (c *Capability) rejected(op protocol.OperationEnvelope, code, message, field string) protocol.ResultEnvelope {
	digest, generationID := formula(op.IdempotencyKey)

	var fieldPtr *string
	if field != "" {
		fieldPtr = &field
	}

	return c.buildResult(op, protocol.StatusRejected, digest, generationID, []protocol.ResultError{{Code: code, Message: message, Field: fieldPtr}})
}

func (c *Capability) buildResult(op protocol.OperationEnvelope, status protocol.Status, digest, generationID string, errs []protocol.ResultError) protocol.ResultEnvelope {
	if errs == nil {
		errs = []protocol.ResultError{}
	}

	return protocol.ResultEnvelope{
		ProtocolVersion:      op.ProtocolVersion,
		Capability:           op.Capability,
		ResourceID:           op.ResourceID,
		IdempotencyKey:       op.IdempotencyKey,
		CorrelationID:        op.CorrelationID,
		Status:               status,
		ObservedStateVersion: op.DesiredStateVersion,
		ObservedStateDigest:  digest,
		GenerationID:         generationID,
		Errors:               errs,
		CompletedAt:          time.Now().UTC(),
	}
}

func decodePayload(raw json.RawMessage, p *payload) error {
	return json.Unmarshal(raw, p)
}

// formula reproduces FakeProvisioner.php's apply() exactly:
//
//	observedStateDigest: 'sha256:'.hash('sha256', 'fake:'.$operation->idempotency_key)
//	generationId: 'fake-'.$operation->idempotency_key
func formula(idempotencyKey string) (digest, generationID string) {
	sum := sha256.Sum256([]byte("fake:" + idempotencyKey))

	return "sha256:" + hex.EncodeToString(sum[:]), "fake-" + idempotencyKey
}
