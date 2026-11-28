package agentupgrade

import (
	"encoding/json"
	"errors"
	"strings"
	"testing"

	"github.com/mikho/LESta/agent/internal/protocol"
)

const validateResourceID = "00000000-0000-4000-8000-000000000001"

func TestValidatePayloadAcceptsASignedRequest(t *testing.T) {
	f := newFixture(t)

	for _, mutate := range []func(*payload){nil, func(p *payload) { p.NextPublicKey = strings.Repeat("A", 43) + "=" }} {
		if err := ValidatePayload(protocol.OperationUpdate, validateResourceID, f.request(mutate).Payload); err != nil {
			t.Error(err)
		}
	}
}

func TestValidatePayloadRefusesHostilePayloadsWithApplysCode(t *testing.T) {
	f := newFixture(t)
	long := strings.Repeat("a", 4096)

	for _, tc := range []struct {
		name   string
		mutate func(*payload)
		code   string
	}{
		{"version", func(p *payload) { p.Version = "1.0.90\n" }, "invalid_version"},
		{"version path", func(p *payload) { p.Version = "../1.0.90" }, "invalid_version"},
		{"sha256", func(p *payload) { p.SHA256 = strings.ToUpper(p.SHA256) }, "invalid_sha256"},
		{"next key", func(p *payload) { p.NextPublicKey = "not base64\x00" }, "invalid_next_key"},
		{"signature", func(p *payload) { p.Signature = long }, "invalid_signature"},
		{"download path", func(p *payload) { p.DownloadURL = f.server.URL + "/agent/v1/../agent-binary" }, "invalid_download"},
		{"download query", func(p *payload) { p.DownloadURL += "?x=1" }, "invalid_download"},
		{"download no host", func(p *payload) { p.DownloadURL = "/agent/v1/agent-binary" }, "invalid_download"},
		{"token newline", func(p *payload) { p.DownloadToken = token + "\n" }, "invalid_download"},
		{"token long", func(p *payload) { p.DownloadToken = long }, "invalid_download"},
	} {
		err := ValidatePayload(protocol.OperationUpdate, validateResourceID, f.request(tc.mutate).Payload)
		assertCode(t, tc.name, err, tc.code)

		res, _ := f.cap.Apply(t.Context(), f.request(tc.mutate))
		if res.Status != protocol.StatusRejected || len(res.Errors) != 1 || res.Errors[0].Code != tc.code {
			t.Errorf("%s: Apply gave %s %+v, want %s", tc.name, res.Status, res.Errors, tc.code)
		}
	}

	for _, tc := range []struct {
		name      string
		operation protocol.Operation
		raw       string
		code      string
	}{
		{"create", protocol.OperationCreate, `{}`, "unsupported_operation"},
		{"delete", protocol.OperationDelete, `{}`, "unsupported_operation"},
		{"unknown field", protocol.OperationUpdate, `{"version":"1.0.90","binary_path":"/tmp/x"}`, "invalid_payload"},
		{"not json", protocol.OperationUpdate, `nope`, "invalid_payload"},
	} {
		assertCode(t, tc.name, ValidatePayload(tc.operation, validateResourceID, json.RawMessage(tc.raw)), tc.code)
	}
}

func assertCode(t *testing.T, name string, err error, code string) {
	t.Helper()

	var verr *ValidationError
	if !errors.As(err, &verr) || verr.Code != code {
		t.Errorf("%s: got %v, want code %s", name, err, code)
	}
}
