package mail

import (
	"context"
	"fmt"
	"net"
	"net/textproto"
	"os"
	"os/exec"
	"strconv"
	"strings"
	"syscall"
	"time"
)

// reloadExim issues a real Exim reload. Config.EximReloadCommand, when set,
// fully overrides the command (the seam failure-injection tests and a real
// `systemctl reload exim4` both use); the default sends SIGHUP directly to
// the pid in Config.EximPIDFile, Exim's own documented reload mechanism.
func (c *MailCapability) reloadExim(ctx context.Context) error {
	if len(c.cfg.EximReloadCommand) > 0 {
		cmd := exec.CommandContext(ctx, c.cfg.EximReloadCommand[0], c.cfg.EximReloadCommand[1:]...)

		out, err := cmd.CombinedOutput()
		if err != nil {
			return fmt.Errorf("exim reload command failed: %w: %s", err, strings.TrimSpace(string(out)))
		}

		return nil
	}

	pid, err := readPIDFile(c.cfg.EximPIDFile)
	if err != nil {
		return fmt.Errorf("reading exim pid file %s: %w", c.cfg.EximPIDFile, err)
	}

	proc, err := os.FindProcess(pid)
	if err != nil {
		return fmt.Errorf("finding exim process %d: %w", pid, err)
	}

	if err := proc.Signal(syscall.SIGHUP); err != nil {
		return fmt.Errorf("sending SIGHUP to exim process %d: %w", pid, err)
	}

	return nil
}

// reloadDovecot issues a real Dovecot reload. Config.DovecotReloadCommand,
// when set, fully overrides the command; the default is `doveadm reload`,
// Dovecot's own documented reload mechanism (re-reads config and passwd
// files without dropping existing connections).
func (c *MailCapability) reloadDovecot(ctx context.Context) error {
	var cmd *exec.Cmd

	if len(c.cfg.DovecotReloadCommand) > 0 {
		cmd = exec.CommandContext(ctx, c.cfg.DovecotReloadCommand[0], c.cfg.DovecotReloadCommand[1:]...)
	} else {
		cmd = exec.CommandContext(ctx, c.cfg.doveadmBinary(), "reload")
	}

	out, err := cmd.CombinedOutput()
	if err != nil {
		return fmt.Errorf("dovecot reload failed: %w: %s", err, strings.TrimSpace(string(out)))
	}

	return nil
}

func readPIDFile(path string) (int, error) {
	raw, err := os.ReadFile(path)
	if err != nil {
		return 0, err
	}

	return strconv.Atoi(strings.TrimSpace(string(raw)))
}

// waitSMTPRejects polls a real SMTP session against this node's own live
// Exim, confirming a RCPT TO for a non-hosted domain (or a hosted domain's
// unknown local part) is genuinely rejected at the protocol level -- the
// real, on-the-wire proof of this capability's own most important
// correctness property (per the Mail Threat Model's own non-negotiable: no
// relay for a domain this node does not host, no unauthenticated delivery
// to an address that doesn't exist). This is the mail equivalent of bind9's
// own "real DNS query for a marker TXT record" health check: a live
// protocol round trip against the real daemon, never a config-file read.
func (c *MailCapability) waitSMTPRejects(ctx context.Context, rcptAddress string) error {
	addr := net.JoinHostPort(c.cfg.eximListenAddress(), strconv.Itoa(c.cfg.EximListenPort))

	var lastErr error

	for {
		if err := probeSMTPRcptRejected(ctx, addr, rcptAddress); err == nil {
			return nil
		} else {
			lastErr = err
		}

		select {
		case <-ctx.Done():
			return fmt.Errorf("SMTP reject health check for %s did not succeed before deadline: %w (last error: %v)", rcptAddress, ctx.Err(), lastErr)
		case <-time.After(50 * time.Millisecond):
		}
	}
}

// waitSMTPAccepts is probeSMTPRcptRejected's own counterpart, proving a real
// hosted, active address is genuinely accepted at RCPT TO time, not merely
// that unrelated addresses are rejected: a health check that only ever
// checks the reject path could stay green even if this apply's own new
// account were never actually reachable.
func (c *MailCapability) waitSMTPAccepts(ctx context.Context, rcptAddress string) error {
	addr := net.JoinHostPort(c.cfg.eximListenAddress(), strconv.Itoa(c.cfg.EximListenPort))

	var lastErr error

	for {
		if err := probeSMTPRcptAccepted(ctx, addr, rcptAddress); err == nil {
			return nil
		} else {
			lastErr = err
		}

		select {
		case <-ctx.Done():
			return fmt.Errorf("SMTP accept health check for %s did not succeed before deadline: %w (last error: %v)", rcptAddress, ctx.Err(), lastErr)
		case <-time.After(50 * time.Millisecond):
		}
	}
}

func probeSMTPRcptAccepted(ctx context.Context, addr, rcptAddress string) error {
	var d net.Dialer

	conn, err := d.DialContext(ctx, "tcp", addr)
	if err != nil {
		return err
	}
	defer conn.Close()

	tp := textproto.NewConn(conn)
	defer tp.Close()

	if _, _, err := tp.ReadResponse(220); err != nil {
		return fmt.Errorf("reading banner: %w", err)
	}

	if err := tp.PrintfLine("EHLO lesta-healthcheck"); err != nil {
		return err
	}
	if _, _, err := tp.ReadResponse(250); err != nil {
		return fmt.Errorf("EHLO: %w", err)
	}

	if err := tp.PrintfLine("MAIL FROM:<healthcheck@lesta-internal.invalid>"); err != nil {
		return err
	}
	if _, _, err := tp.ReadResponse(250); err != nil {
		return fmt.Errorf("MAIL FROM: %w", err)
	}

	if err := tp.PrintfLine("RCPT TO:<%s>", rcptAddress); err != nil {
		return err
	}

	code, _, err := tp.ReadResponse(0)
	if err != nil {
		return fmt.Errorf("reading RCPT TO response: %w", err)
	}

	_ = tp.PrintfLine("QUIT")

	if code < 200 || code >= 300 {
		return fmt.Errorf("expected RCPT TO %s to be accepted (2xx), got %d", rcptAddress, code)
	}

	return nil
}

// probeSMTPRejects performs one real SMTP session: connect, read the
// banner, EHLO, MAIL FROM, RCPT TO rcptAddress, and requires a 5xx
// rejection; anything else (a 2xx accept, a connection failure) is an
// error. QUIT is sent best-effort; this function never leaves a connection
// open on either success or failure.
func probeSMTPRcptRejected(ctx context.Context, addr, rcptAddress string) error {
	var d net.Dialer

	conn, err := d.DialContext(ctx, "tcp", addr)
	if err != nil {
		return err
	}
	defer conn.Close()

	tp := textproto.NewConn(conn)
	defer tp.Close()

	if _, _, err := tp.ReadResponse(220); err != nil {
		return fmt.Errorf("reading banner: %w", err)
	}

	if err := tp.PrintfLine("EHLO lesta-healthcheck"); err != nil {
		return err
	}
	if _, _, err := tp.ReadResponse(250); err != nil {
		return fmt.Errorf("EHLO: %w", err)
	}
	// A real multi-line EHLO reply's continuation lines are already
	// consumed by ReadResponse itself (net/textproto follows RFC 5321's
	// "250-"/"250 " continuation convention automatically).

	if err := tp.PrintfLine("MAIL FROM:<healthcheck@lesta-internal.invalid>"); err != nil {
		return err
	}
	if _, _, err := tp.ReadResponse(250); err != nil {
		return fmt.Errorf("MAIL FROM: %w", err)
	}

	if err := tp.PrintfLine("RCPT TO:<%s>", rcptAddress); err != nil {
		return err
	}

	code, _, err := tp.ReadResponse(0)
	if err != nil {
		return fmt.Errorf("reading RCPT TO response: %w", err)
	}

	_ = tp.PrintfLine("QUIT")

	if code < 500 || code >= 600 {
		return fmt.Errorf("expected RCPT TO %s to be rejected (5xx), got %d", rcptAddress, code)
	}

	return nil
}
