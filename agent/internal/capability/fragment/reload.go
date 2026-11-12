package fragment

import (
	"fmt"
	"os/exec"
	"strings"
)

// Reload runs the service's reload command as its own separate step from activation, folding the
// command's output into the error. what names the step in that error: "nginx reload".
func Reload(cmd *exec.Cmd, what string) error {
	out, err := cmd.CombinedOutput()
	if err != nil {
		return fmt.Errorf("%s failed: %w: %s", what, err, strings.TrimSpace(string(out)))
	}

	return nil
}
