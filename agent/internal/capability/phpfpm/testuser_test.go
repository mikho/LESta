package phpfpm_test

import (
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
