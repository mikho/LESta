package apache

import "testing"

// TestParsePayloadAcceptsTheRealControlPlaneShape pins the exact key set
// WebDomain::toProvisioningPayload() sends, so a new Laravel key can never
// again make DisallowUnknownFields() reject every real apache payload.
func TestParsePayloadAcceptsTheRealControlPlaneShape(t *testing.T) {
	raw := []byte(`{"domain":"a.example","aliases":[],"ip_address":"127.0.0.1","web_template":"default","account_id":1,"account_username":"lesta-a1","php_socket":null,"adminer_socket":null,"webmail_socket":null,"ssl":{"mode":"off"},"suspended":false}`)

	if _, err := ParsePayload(raw); err != nil {
		t.Fatalf("expected the real control-plane payload to parse, got: %v", err)
	}
}
