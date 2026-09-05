package cron

import (
	"bytes"
	"encoding/json"
	"fmt"
	"regexp"
	"strconv"
	"strings"
)

// runAsPattern enforces standard Linux system-username constraints on
// RunAs, deliberately duplicated from internal/capability/identity's own
// identical pattern rather than shared/imported (matching the
// "duplicate, don't import" precedent internal/capability/acme's own
// hostnamePattern doc comment already established for this module). RunAs
// is embedded directly into a crontab fragment's own line AND used to build
// this resource's own sidecar/execution-log filesystem paths (see
// capability.go's own sidecarPath/executionLogPath), so rejecting anything
// outside this charset before either use defends against both crontab-line
// injection and path traversal via a "../"-laden value, even though Laravel
// is the one computing this value deterministically and should never send
// anything else.
var runAsPattern = regexp.MustCompile(`^[a-z][a-z0-9_-]{0,31}$`)

// Payload is the scheduler.account-cron.v1 capability's request body. The
// five schedule fields are embedded directly into the crontab fragment's own
// line, so each is validated against a real cron-field grammar, numerically
// range-checked, not just regex-shaped (see validateCronField). Command
// never appears in the crontab fragment itself: it is written only to this
// resource's own JSON sidecar (see capability.go's own applyWrite), read
// only by the cron-run wrapper at execution time.
type Payload struct {
	Minute     string `json:"minute"`
	Hour       string `json:"hour"`
	DayOfMonth string `json:"day_of_month"`
	Month      string `json:"month"`
	DayOfWeek  string `json:"day_of_week"`
	Command    string `json:"command"`
	Suspended  bool   `json:"suspended"`
	// RunAs is the crontab fragment's own user-column value: the tenant
	// account's dedicated, per-node Linux system user
	// (system.account-identity.v1's own deterministic username, e.g.
	// "lesta-t42"), never a shared identity, except for the installer's own
	// synthetic self-test job, which points this at the shared "lesta-cron"
	// identity explicitly (see .install/services/cron/install.sh's own
	// run_node_health_selftest). Also doubles as this payload's own
	// per-account state key: renderFragment embeds it directly in the
	// crontab line, and capability.go/runner.go both key every sidecar and
	// execution-log path by this same value (see this package's own
	// sidecarPath/executionLogPath), since RunAs and "the account this job
	// belongs to" are the same value by construction -- CreateCronJob always
	// resolves both from the very same AccountNodeIdentity row.
	RunAs string `json:"run_as"`
}

// ValidationError is a well-formed payload rejection: a schema-shaped (code,
// message, field) triple the caller turns directly into a rejected
// ResultEnvelope. It is never a Go error representing "no verdict was
// reached".
type ValidationError struct {
	Code    string
	Message string
	Field   string
}

func (e *ValidationError) Error() string {
	return fmt.Sprintf("%s: %s (field=%s)", e.Code, e.Message, e.Field)
}

// ParsePayload decodes and validates raw as a Payload. Unknown fields are a
// hard decode error, matching the envelope decode discipline of every other
// capability.
func ParsePayload(raw json.RawMessage) (Payload, error) {
	var p Payload

	dec := json.NewDecoder(bytes.NewReader(raw))
	dec.DisallowUnknownFields()
	if err := dec.Decode(&p); err != nil {
		return Payload{}, fmt.Errorf("decoding cron payload: %w", err)
	}

	type scheduleField struct {
		name     string
		value    string
		min, max int
	}

	// Cron allows both 0 and 7 for Sunday in the day-of-week field.
	fields := []scheduleField{
		{"minute", p.Minute, 0, 59},
		{"hour", p.Hour, 0, 23},
		{"day_of_month", p.DayOfMonth, 1, 31},
		{"month", p.Month, 1, 12},
		{"day_of_week", p.DayOfWeek, 0, 7},
	}

	for _, f := range fields {
		if !validateCronField(f.value, f.min, f.max) {
			return Payload{}, &ValidationError{
				Code:    "invalid_schedule_field",
				Message: fmt.Sprintf("%s %q is not a valid cron field for the range %d-%d", f.name, f.value, f.min, f.max),
				Field:   f.name,
			}
		}
	}

	if verr := validateCommand(p.Command); verr != nil {
		return Payload{}, verr
	}

	if p.RunAs == "" || !runAsPattern.MatchString(p.RunAs) {
		return Payload{}, &ValidationError{
			Code:    "invalid_run_as",
			Message: "run_as must be a non-empty string matching ^[a-z][a-z0-9_-]{0,31}$",
			Field:   "run_as",
		}
	}

	return p, nil
}

// validateCronField reports whether value is a well-formed cron field: a
// comma-separated list of items, each either "*", a single integer, or a
// range "N-M", optionally suffixed with "/step" (e.g. "*/15", "1-5/2").
// Every integer found (including both ends of a range) must fall within
// [min, max]; a range's start must not exceed its end. There is no external
// crontab-syntax-checker binary this capability can lean on (unlike
// nginx's/bind9's own -t/-checkconf validators), so this is the "heavier
// validation layer" this capability alone needs.
func validateCronField(value string, min, max int) bool {
	if value == "" {
		return false
	}

	for _, item := range strings.Split(value, ",") {
		if item == "" {
			return false
		}

		parts := strings.SplitN(item, "/", 2)
		base := parts[0]

		if len(parts) == 2 {
			step, err := strconv.Atoi(parts[1])
			if err != nil || step < 1 {
				return false
			}
		}

		if base == "*" {
			continue
		}

		if strings.Contains(base, "-") {
			rangeParts := strings.SplitN(base, "-", 2)
			if len(rangeParts) != 2 {
				return false
			}

			lo, errLo := strconv.Atoi(rangeParts[0])
			hi, errHi := strconv.Atoi(rangeParts[1])
			if errLo != nil || errHi != nil {
				return false
			}
			if lo < min || lo > max || hi < min || hi > max || lo > hi {
				return false
			}

			continue
		}

		n, err := strconv.Atoi(base)
		if err != nil || n < min || n > max {
			return false
		}
	}

	return true
}

// validateCommand checks Command for the defense-in-depth guards this
// capability applies on top of the wrapper-invocation design (see this
// package's own doc comment): the command text never reaches the crontab
// fragment's own syntax at all, so these checks are not the primary
// injection safeguard, only additional hardening against a hypothetical
// future design change.
func validateCommand(command string) *ValidationError {
	trimmed := strings.TrimSpace(command)

	if trimmed == "" {
		return &ValidationError{Code: "invalid_command", Message: "command must not be empty", Field: "command"}
	}

	if len(command) > 1024 {
		return &ValidationError{Code: "invalid_command", Message: "command must not exceed 1024 bytes", Field: "command"}
	}

	if strings.ContainsAny(command, "\n\r") {
		return &ValidationError{Code: "invalid_command", Message: "command must not contain a newline or carriage return", Field: "command"}
	}

	// Best-effort, explicitly non-exhaustive: this is not the real security
	// boundary (the fixed non-root RunnerUser identity is), only a surface
	// check against an obviously misguided attempt to escalate privilege
	// from within a job's own command text.
	lower := strings.ToLower(strings.TrimLeft(trimmed, " \t"))
	if strings.HasPrefix(lower, "sudo ") || strings.HasPrefix(lower, "su ") {
		return &ValidationError{Code: "invalid_command", Message: "command must not attempt to run as another user via sudo/su; every job already runs as a fixed non-root user", Field: "command"}
	}

	return nil
}
