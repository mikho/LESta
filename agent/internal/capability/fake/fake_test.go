package fake_test

import (
	"testing"

	"github.com/mikho/LESta/agent/internal/capability/fake"
	"github.com/mikho/LESta/agent/internal/contract"
	"github.com/mikho/LESta/agent/internal/protocol"
)

// TestFakeCapabilitySatisfiesContract runs the exact same shared contract
// suite internal/capability/nginx's real capability must also pass.
func TestFakeCapabilitySatisfiesContract(t *testing.T) {
	contract.RunAgainst(t, func(t *testing.T) protocol.Capability {
		return fake.New()
	})
}
