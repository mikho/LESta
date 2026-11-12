package protocol

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

type digestVector struct {
	Name                string `json:"name"`
	Capability          string `json:"capability"`
	Operation           string `json:"operation"`
	ResourceID          string `json:"resource_id"`
	DesiredStateVersion int    `json:"desired_state_version"`
	PayloadWire         string `json:"payload_wire"`
	Digest              string `json:"digest"`
}

func (v digestVector) envelope() OperationEnvelope {
	return OperationEnvelope{
		Capability:          v.Capability,
		Operation:           Operation(v.Operation),
		ResourceID:          v.ResourceID,
		DesiredStateVersion: v.DesiredStateVersion,
		RequestDigest:       v.Digest,
		Payload:             json.RawMessage(v.PayloadWire),
	}
}

// The vectors are produced by the panel's own implementation (PHP), so this proves both sides
// agree on every case, escapes and key order included.
func TestRequestDigestMatchesPanelVectors(t *testing.T) {
	raw, err := os.ReadFile(filepath.Join(repoRootFromThisFile(t), "docs", "protocol", "request-digest-vectors.json"))
	if err != nil {
		t.Fatalf("reading vectors: %v", err)
	}

	var vectors []digestVector
	if err := json.Unmarshal(raw, &vectors); err != nil {
		t.Fatalf("parsing vectors: %v", err)
	}

	if len(vectors) == 0 {
		t.Fatal("no vectors")
	}

	for _, v := range vectors {
		t.Run(v.Name, func(t *testing.T) {
			got, err := RequestDigest(v.envelope())
			if err != nil {
				t.Fatal(err)
			}

			if got != v.Digest {
				t.Fatalf("digest = %s, panel computed %s", got, v.Digest)
			}

			if err := VerifyRequestDigest(v.envelope()); err != nil {
				t.Fatalf("a matching digest was refused: %v", err)
			}
		})
	}
}

func TestVerifyRequestDigestRefusesAnyChangedField(t *testing.T) {
	base := OperationEnvelope{
		Capability:          "web.nginx.v1",
		Operation:           OperationUpdate,
		ResourceID:          "11111111-1111-1111-1111-111111111111",
		DesiredStateVersion: 7,
		Payload:             json.RawMessage(`{"domain":"example.com"}`),
	}

	digest, err := RequestDigest(base)
	if err != nil {
		t.Fatal(err)
	}

	base.RequestDigest = digest

	if err := VerifyRequestDigest(base); err != nil {
		t.Fatalf("the untouched operation was refused: %v", err)
	}

	changes := map[string]func(*OperationEnvelope){
		"capability": func(o *OperationEnvelope) { o.Capability = "web.apache.v1" },
		"operation":  func(o *OperationEnvelope) { o.Operation = OperationDelete },
		"resource":   func(o *OperationEnvelope) { o.ResourceID = "22222222-2222-2222-2222-222222222222" },
		"version":    func(o *OperationEnvelope) { o.DesiredStateVersion = 8 },
		"payload":    func(o *OperationEnvelope) { o.Payload = json.RawMessage(`{"domain":"example.org"}`) },
		"digest":     func(o *OperationEnvelope) { o.RequestDigest = "sha256:" + strings.Repeat("0", 64) },
		"no digest":  func(o *OperationEnvelope) { o.RequestDigest = "" },
	}

	for name, change := range changes {
		op := base
		change(&op)

		if err := VerifyRequestDigest(op); err == nil {
			t.Errorf("%s: a changed operation was accepted", name)
		}
	}
}

func TestRequestDigestIgnoresKeyOrderAndWhitespace(t *testing.T) {
	a := OperationEnvelope{Capability: "c", Operation: OperationCreate, ResourceID: "r", DesiredStateVersion: 1, Payload: json.RawMessage(`{"a":1,"b":{"x":1,"y":2}}`)}
	b := a
	b.Payload = json.RawMessage("{ \"b\": {\"y\": 2, \"x\": 1},\n \"a\": 1 }")

	da, err := RequestDigest(a)
	if err != nil {
		t.Fatal(err)
	}

	db, err := RequestDigest(b)
	if err != nil {
		t.Fatal(err)
	}

	if da != db {
		t.Fatalf("the same payload written differently gave %s and %s", da, db)
	}
}

func TestRequestDigestRejectsMalformedPayload(t *testing.T) {
	for _, payload := range []string{``, `{`, `{} {}`, `nope`} {
		op := OperationEnvelope{Capability: "c", Operation: OperationCreate, ResourceID: "r", DesiredStateVersion: 1, Payload: json.RawMessage(payload)}

		if _, err := RequestDigest(op); err == nil {
			t.Errorf("payload %q was accepted", payload)
		}
	}
}
