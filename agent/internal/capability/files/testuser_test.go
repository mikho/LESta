package files_test

import (
	"fmt"
	"math/rand/v2"
	"os/user"

	"github.com/mikho/LESta/agent/internal/validate"
)

// The tests run without root, as whatever user the build machine or CI uses, and own the files
// they create as that user and its primary group: let those stand in for an account's user here.
func init() {
	if current, err := user.Current(); err == nil {
		validate.AllowAccountUsernameForTesting(current.Username)

		if group, err := user.LookupGroupId(current.Gid); err == nil {
			validate.AllowAccountUsernameForTesting(group.Name)
		}
	}
}

// newTestAccountUsername returns a fresh name of the shape every real account's user has
// (lesta-t<digits>), for the root-only tests that create a real system user.
func newTestAccountUsername() string {
	return fmt.Sprintf("lesta-t9%08d", rand.IntN(100_000_000))
}
