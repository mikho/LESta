package sshaccess

import (
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/mikho/LESta/agent/internal/protocol"
)

// fakeNft records every script it is given and fails when told to.
func fakeNft(t *testing.T, fail bool) (string, string) {
	t.Helper()

	dir := t.TempDir()
	log := filepath.Join(dir, "scripts")
	path := filepath.Join(dir, "nft")
	exit := "0"
	if fail {
		exit = "1"
	}

	script := "#!/bin/sh\ncat >> " + log + "\necho '----' >> " + log + "\n[ " + exit + " = 1 ] && { echo 'Error: Operation not permitted' >&2; exit 1; }\nexit 0\n"
	if err := os.WriteFile(path, []byte(script), 0o755); err != nil {
		t.Fatal(err)
	}

	return path, log
}

func newCapability(t *testing.T, fail bool) (*SSHAccessCapability, string, string) {
	t.Helper()

	nft, log := fakeNft(t, fail)
	closed := filepath.Join(t.TempDir(), "ssh-closed.nft")

	return New(Config{NftBinary: nft, ClosedPath: closed, Port: 22}), closed, log
}

func op(key, body string) protocol.OperationEnvelope {
	return protocol.OperationEnvelope{
		ProtocolVersion:     "1",
		Capability:          "node.ssh-access.v1",
		Operation:           protocol.OperationUpdate,
		ResourceID:          "00000000-0000-4000-8000-000000000001",
		DesiredStateVersion: 1,
		IdempotencyKey:      key,
		CorrelationID:       "00000000-0000-4000-8000-000000000002",
		Payload:             json.RawMessage(body),
	}
}

func TestClosingDropsNewConnectionsToThePortAndKeepsTheStateForTheNextBoot(t *testing.T) {
	c, closed, log := newCapability(t, false)

	res, err := c.Apply(context.Background(), op("k1", `{"open":false}`))
	if err != nil || res.Status != protocol.StatusApplied {
		t.Fatalf("status %s, err %v, errors %+v", res.Status, err, res.Errors)
	}

	if string(res.Data) != `{"open":false}` {
		t.Errorf("data = %s", res.Data)
	}

	scripts, _ := os.ReadFile(log)
	if !strings.Contains(string(scripts), "ct state new tcp dport 22 drop") || !strings.Contains(string(scripts), "priority -10") {
		t.Errorf("the table loaded was %q", scripts)
	}

	kept, err := os.ReadFile(closed)
	if err != nil || !strings.Contains(string(kept), "tcp dport 22 drop") {
		t.Fatalf("the closed state was not kept: %v %q", err, kept)
	}
}

func TestOpeningDeletesTheTableAndTheKeptState(t *testing.T) {
	c, closed, log := newCapability(t, false)

	if _, err := c.Apply(context.Background(), op("k1", `{"open":false}`)); err != nil {
		t.Fatal(err)
	}

	res, err := c.Apply(context.Background(), op("k2", `{"open":true}`))
	if err != nil || res.Status != protocol.StatusApplied || string(res.Data) != `{"open":true}` {
		t.Fatalf("status %s, err %v, data %s", res.Status, err, res.Data)
	}

	if _, err := os.Stat(closed); !os.IsNotExist(err) {
		t.Errorf("the kept state is still there: %v", err)
	}

	scripts, _ := os.ReadFile(log)
	if !strings.HasSuffix(strings.TrimSpace(string(scripts)), "add table inet lesta_ssh\ndelete table inet lesta_ssh\n----") {
		t.Errorf("the last script was %q", scripts)
	}
}

func TestOpeningWhenItWasNeverClosedIsHarmless(t *testing.T) {
	c, _, _ := newCapability(t, false)

	res, err := c.Apply(context.Background(), op("k1", `{"open":true}`))
	if err != nil || res.Status != protocol.StatusApplied {
		t.Fatalf("status %s, err %v, errors %+v", res.Status, err, res.Errors)
	}
}

func TestAReplayIsAnsweredWithoutTouchingTheFirewallAgain(t *testing.T) {
	c, _, log := newCapability(t, false)

	if _, err := c.Apply(context.Background(), op("k1", `{"open":false}`)); err != nil {
		t.Fatal(err)
	}

	before, _ := os.ReadFile(log)

	again, _ := c.Apply(context.Background(), op("k1", `{"open":false}`))
	if again.Status != protocol.StatusAlreadyApplied {
		t.Errorf("status = %s", again.Status)
	}

	if after, _ := os.ReadFile(log); string(after) != string(before) {
		t.Errorf("nft was run again")
	}
}

func TestAFirewallFailureIsReportedNotAppliedAndStillClosesAfterABoot(t *testing.T) {
	c, closed, _ := newCapability(t, true)

	res, _ := c.Apply(context.Background(), op("k1", `{"open":false}`))
	if res.Status != protocol.StatusFailed || len(res.Errors) != 1 || res.Errors[0].Code != "firewall_failed" {
		t.Fatalf("status %s, errors %+v", res.Status, res.Errors)
	}

	if _, err := os.Stat(closed); err != nil {
		t.Errorf("the state the panel asked for was not kept: %v", err)
	}
}

func TestMalformedOperationsAreRejectedWithoutTouchingTheFirewall(t *testing.T) {
	c, _, log := newCapability(t, false)

	for i, body := range []string{`{}`, `{"open":"yes"}`, `{"open":true,"extra":1}`, `[]`, `nope`} {
		res, _ := c.Apply(context.Background(), op("k"+string(rune('a'+i)), body))
		if res.Status != protocol.StatusRejected {
			t.Errorf("%s: status = %s", body, res.Status)
		}
	}

	del := op("kz", `{"open":true}`)
	del.Operation = protocol.OperationDelete

	if res, _ := c.Apply(context.Background(), del); res.Status != protocol.StatusRejected {
		t.Errorf("delete: status = %s", res.Status)
	}

	if _, err := os.Stat(log); !os.IsNotExist(err) {
		t.Errorf("nft was run for a malformed operation")
	}
}

func TestRestoreClosesThePortOnlyWhenItWasClosed(t *testing.T) {
	c, closed, log := newCapability(t, false)

	if err := Restore(context.Background(), c.cfg); err != nil {
		t.Fatal(err)
	}

	if _, err := os.Stat(log); !os.IsNotExist(err) {
		t.Fatalf("nft was run for a port that was never closed")
	}

	if err := os.WriteFile(closed, []byte(c.cfg.closedScript()), 0o600); err != nil {
		t.Fatal(err)
	}

	if err := Restore(context.Background(), c.cfg); err != nil {
		t.Fatal(err)
	}

	scripts, _ := os.ReadFile(log)
	if !strings.Contains(string(scripts), "tcp dport 22 drop") {
		t.Errorf("the port was not closed again: %q", scripts)
	}
}
