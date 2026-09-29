package cron

import (
	"bytes"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
)

// resourceIDPattern is a strict UUID shape. Unlike runAsPattern (already
// validated once by ParsePayload before ever reaching this package's own
// write paths), a resource_id reaching InstallFragment/RemoveFragment
// crosses a real process boundary (sudo, running as root) rather than just
// an in-process call, so it is re-validated here from scratch: this
// package's own security boundary for that boundary must hold on its own,
// never by trusting that some earlier layer already checked.
var resourceIDPattern = regexp.MustCompile(`^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$`)

// fragmentPathFor returns fragmentDir/lesta-<resourceID>, the free-function
// form of (*CronCapability).fragmentPath so InstallFragment/RemoveFragment
// (package-level CLI entry points with no CronCapability instance of their
// own, mirroring RunJob's own shape in runner.go) can build the identical
// path.
func fragmentPathFor(fragmentDir, resourceID string) string {
	return fragmentDir + "/lesta-" + resourceID
}

// sidecarPathFor is the free-function form of (*CronCapability).sidecarPath,
// so InstallSidecar/RemoveSidecar (package-level CLI entry points with no
// CronCapability instance of their own) can build the identical path.
func sidecarPathFor(stateRoot, runAs, resourceID string) string {
	return filepath.Join(accountDirFor(stateRoot, runAs), "jobs", "sidecar", resourceID+".json")
}

// installFragment writes content to this resource's own crontab fragment,
// either directly (cfg.SudoBinary empty) or via a real sudo invocation of
// this same binary's own "cron-install-fragment" CLI mode, content piped
// over stdin rather than an argument (a crontab fragment can be arbitrarily
// long; command-line length limits and argv-visible-in-ps concerns both
// argue against passing it as an argument instead).
func (c *CronCapability) installFragment(resourceID string, content []byte) error {
	if c.cfg.SudoBinary == "" {
		return writeFileAtomic(fragmentPathFor(c.cfg.FragmentDir, resourceID), content, 0o644)
	}

	cmd := exec.Command(c.cfg.SudoBinary, c.cfg.AgentBinaryPath, "cron-install-fragment", resourceID) //nolint:gosec // fixed binary, argv-validated resource_id, no shell
	cmd.Stdin = bytes.NewReader(content)

	if out, err := cmd.CombinedOutput(); err != nil {
		return fmt.Errorf("installing crontab fragment for %s via sudo: %w: %s", resourceID, err, out)
	}

	return nil
}

// removeFragment removes this resource's own crontab fragment, tolerating
// its own absence either way (matching applyDelete's own pre-existing
// idempotency contract) -- either directly, or via this same binary's own
// "cron-remove-fragment" CLI mode under sudo.
func (c *CronCapability) removeFragment(resourceID string) error {
	if c.cfg.SudoBinary == "" {
		if err := os.Remove(fragmentPathFor(c.cfg.FragmentDir, resourceID)); err != nil && !os.IsNotExist(err) {
			return err
		}

		return nil
	}

	cmd := exec.Command(c.cfg.SudoBinary, c.cfg.AgentBinaryPath, "cron-remove-fragment", resourceID) //nolint:gosec // fixed binary, argv-validated resource_id, no shell

	if out, err := cmd.CombinedOutput(); err != nil {
		return fmt.Errorf("removing crontab fragment for %s via sudo: %w: %s", resourceID, err, out)
	}

	return nil
}

// ensureAccountDirPrivileged runs ensureAccountDir directly (cfg.SudoBinary
// empty) or, in production, via this same binary's own
// "cron-ensure-account-dir" CLI mode under sudo: ensureAccountDir's own
// os.Chown to runAs's dedicated primary group requires root (or membership
// in that group, which the real lesta-agent-daemon system user never has
// and never should), confirmed directly deploying to a real node.
func (c *CronCapability) ensureAccountDirPrivileged(runAs string) error {
	if c.cfg.SudoBinary == "" {
		return ensureAccountDir(c.cfg.StateRoot, runAs)
	}

	cmd := exec.Command(c.cfg.SudoBinary, c.cfg.AgentBinaryPath, "cron-ensure-account-dir", runAs) //nolint:gosec // fixed binary, argv-validated runAs, no shell

	if out, err := cmd.CombinedOutput(); err != nil {
		return fmt.Errorf("ensuring account directory for %s via sudo: %w: %s", runAs, err, out)
	}

	return nil
}

// installSidecar writes content to this resource's own JSON sidecar,
// either directly (cfg.SudoBinary empty) or via this same binary's own
// "cron-install-sidecar" CLI mode under sudo: sidecarPath's own parent,
// StateRoot/accounts/<run_as>, is root:<run_as> mode 2750 (ensureAccountDir,
// deliberately never group-readable by the shared lesta group -- see its
// own doc comment), so the real unprivileged lesta-agent-daemon can never
// write there directly, confirmed empirically deploying to a real node.
func (c *CronCapability) installSidecar(runAs, resourceID string, content []byte) error {
	if c.cfg.SudoBinary == "" {
		return writeFileAtomic(sidecarPathFor(c.cfg.StateRoot, runAs, resourceID), content, 0o640)
	}

	cmd := exec.Command(c.cfg.SudoBinary, c.cfg.AgentBinaryPath, "cron-install-sidecar", runAs, resourceID) //nolint:gosec // fixed binary, argv-validated runAs/resource_id, no shell
	cmd.Stdin = bytes.NewReader(content)

	if out, err := cmd.CombinedOutput(); err != nil {
		return fmt.Errorf("installing sidecar for %s via sudo: %w: %s", resourceID, err, out)
	}

	return nil
}

// removeSidecar removes this resource's own JSON sidecar, tolerating its
// own absence either way (matching applyDelete's own pre-existing
// idempotency contract) -- either directly, or via this same binary's own
// "cron-remove-sidecar" CLI mode under sudo.
func (c *CronCapability) removeSidecar(runAs, resourceID string) error {
	if c.cfg.SudoBinary == "" {
		if err := os.Remove(sidecarPathFor(c.cfg.StateRoot, runAs, resourceID)); err != nil && !os.IsNotExist(err) {
			return err
		}

		return nil
	}

	cmd := exec.Command(c.cfg.SudoBinary, c.cfg.AgentBinaryPath, "cron-remove-sidecar", runAs, resourceID) //nolint:gosec // fixed binary, argv-validated runAs/resource_id, no shell

	if out, err := cmd.CombinedOutput(); err != nil {
		return fmt.Errorf("removing sidecar for %s via sudo: %w: %s", resourceID, err, out)
	}

	return nil
}

// InstallFragment is this CLI mode's own entry point (see
// cmd/lesta-agent/main.go's own "cron-install-fragment" dispatch),
// invoked only via the sudoers rule .install/services/cron/install.sh
// writes, which itself only ever supplies a resource_id this process
// already generated -- resourceID is still re-validated here regardless
// (see resourceIDPattern's own doc comment on why). content is read from r
// (production: os.Stdin) rather than an argument. Returns a process exit
// code, matching RunJob's own shape.
func InstallFragment(cfg Config, resourceID string, r io.Reader) int {
	if !resourceIDPattern.MatchString(resourceID) {
		fmt.Fprintf(os.Stderr, "cron-install-fragment: %q is not a valid resource id\n", resourceID)

		return 1
	}

	content, err := io.ReadAll(r)
	if err != nil {
		fmt.Fprintln(os.Stderr, "cron-install-fragment: reading content from stdin:", err)

		return 1
	}

	if err := writeFileAtomic(fragmentPathFor(cfg.FragmentDir, resourceID), content, 0o644); err != nil {
		fmt.Fprintln(os.Stderr, "cron-install-fragment:", err)

		return 1
	}

	return 0
}

// RemoveFragment is this CLI mode's own entry point (see
// cmd/lesta-agent/main.go's own "cron-remove-fragment" dispatch). Tolerates
// the fragment's own prior absence, matching applyDelete's own idempotency
// contract.
func RemoveFragment(cfg Config, resourceID string) int {
	if !resourceIDPattern.MatchString(resourceID) {
		fmt.Fprintf(os.Stderr, "cron-remove-fragment: %q is not a valid resource id\n", resourceID)

		return 1
	}

	if err := os.Remove(fragmentPathFor(cfg.FragmentDir, resourceID)); err != nil && !os.IsNotExist(err) {
		fmt.Fprintln(os.Stderr, "cron-remove-fragment:", err)

		return 1
	}

	return 0
}

// InstallSidecar is this CLI mode's own entry point (see
// cmd/lesta-agent/main.go's own "cron-install-sidecar" dispatch). Both
// runAs and resourceID are re-validated here regardless of any earlier
// check, for the same reason this file's other entry points do: this is a
// real process boundary (sudo, running as root), so it holds its own
// security boundary rather than trusting an earlier layer.
func InstallSidecar(cfg Config, runAs, resourceID string, r io.Reader) int {
	if !runAsPattern.MatchString(runAs) {
		fmt.Fprintf(os.Stderr, "cron-install-sidecar: %q is not a valid run_as\n", runAs)

		return 1
	}

	if !resourceIDPattern.MatchString(resourceID) {
		fmt.Fprintf(os.Stderr, "cron-install-sidecar: %q is not a valid resource id\n", resourceID)

		return 1
	}

	content, err := io.ReadAll(r)
	if err != nil {
		fmt.Fprintln(os.Stderr, "cron-install-sidecar: reading content from stdin:", err)

		return 1
	}

	if err := writeFileAtomic(sidecarPathFor(cfg.StateRoot, runAs, resourceID), content, 0o640); err != nil {
		fmt.Fprintln(os.Stderr, "cron-install-sidecar:", err)

		return 1
	}

	return 0
}

// RemoveSidecar is this CLI mode's own entry point (see
// cmd/lesta-agent/main.go's own "cron-remove-sidecar" dispatch). Tolerates
// the sidecar's own prior absence, matching applyDelete's own idempotency
// contract.
func RemoveSidecar(cfg Config, runAs, resourceID string) int {
	if !runAsPattern.MatchString(runAs) {
		fmt.Fprintf(os.Stderr, "cron-remove-sidecar: %q is not a valid run_as\n", runAs)

		return 1
	}

	if !resourceIDPattern.MatchString(resourceID) {
		fmt.Fprintf(os.Stderr, "cron-remove-sidecar: %q is not a valid resource id\n", resourceID)

		return 1
	}

	if err := os.Remove(sidecarPathFor(cfg.StateRoot, runAs, resourceID)); err != nil && !os.IsNotExist(err) {
		fmt.Fprintln(os.Stderr, "cron-remove-sidecar:", err)

		return 1
	}

	return 0
}

// EnsureAccountDir is this CLI mode's own entry point (see
// cmd/lesta-agent/main.go's own "cron-ensure-account-dir" dispatch).
// runAs is re-validated against the exact same pattern ParsePayload
// already enforced once upstream, for the same reason resourceIDPattern is
// re-checked in this file's other two entry points: this is a real process
// boundary (sudo, running as root), so it holds its own security boundary
// rather than trusting an earlier layer.
func EnsureAccountDir(cfg Config, runAs string) int {
	if !runAsPattern.MatchString(runAs) {
		fmt.Fprintf(os.Stderr, "cron-ensure-account-dir: %q is not a valid run_as\n", runAs)

		return 1
	}

	if err := ensureAccountDir(cfg.StateRoot, runAs); err != nil {
		fmt.Fprintln(os.Stderr, "cron-ensure-account-dir:", err)

		return 1
	}

	return 0
}
