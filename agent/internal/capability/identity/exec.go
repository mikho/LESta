package identity

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"os/exec"
	"strings"
)

// userExists reports whether username already exists as a system user, via
// `id -u <username>`. A non-zero exit from id (its own documented signal for
// "no such user") is treated as exists == false, nil error, never
// propagated as a Go error; any other failure to even run id (binary
// missing, context deadline) is a real error, matching runSQL's own
// "an *exec.ExitError is a well-formed answer, anything else is a real
// failure" distinction in internal/capability/mariadb/exec.go.
func userExists(ctx context.Context, cfg Config, username string) (bool, error) {
	cmd := exec.CommandContext(ctx, cfg.idBinary(), "-u", username)

	var stderr bytes.Buffer
	cmd.Stderr = &stderr

	if err := cmd.Run(); err != nil {
		var exitErr *exec.ExitError
		if errors.As(err, &exitErr) {
			return false, nil
		}

		return false, err
	}

	return true, nil
}

// createSystemUser execs useradd --system --no-create-home --shell
// /usr/sbin/nologin <username>. Relying on useradd's own platform default
// of also creating a same-named primary group (never passing
// --no-user-group): runner.go's own per-account directory permissions (see
// Part B.4's own StateRoot/accounts/<username> layout) depend on that
// primary group existing so chown root:<username> mode 2750 restricts
// access to this one account's own dedicated Linux user alone.
func createSystemUser(ctx context.Context, cfg Config, username string) error {
	cmd := exec.CommandContext(ctx, cfg.useraddBinary(), "--system", "--no-create-home", "--shell", "/usr/sbin/nologin", username)

	var stderr bytes.Buffer
	cmd.Stderr = &stderr

	if err := cmd.Run(); err != nil {
		return errorWithStderr(err, stderr.String())
	}

	return nil
}

// deleteSystemUser execs userdel <username>.
func deleteSystemUser(ctx context.Context, cfg Config, username string) error {
	cmd := exec.CommandContext(ctx, cfg.userdelBinary(), username)

	var stderr bytes.Buffer
	cmd.Stderr = &stderr

	if err := cmd.Run(); err != nil {
		return errorWithStderr(err, stderr.String())
	}

	return nil
}

// errorWithStderr folds a command's own captured stderr into its error, the
// same "%w: %s" shape runSQL uses in internal/capability/mariadb/exec.go, so
// a failed()'s own Message carries the real diagnostic text, not just an
// opaque exit-status error.
func errorWithStderr(err error, stderr string) error {
	trimmed := strings.TrimSpace(stderr)
	if trimmed == "" {
		return err
	}

	return fmt.Errorf("%w: %s", err, trimmed)
}
