package files

import (
	"bytes"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"regexp"
	"strings"
)

// usernamePattern mirrors identity/payload.go's own usernamePattern
// exactly, re-validated here from scratch since this is a real, separate
// process boundary this package's own security boundary must hold on its
// own.
var usernamePattern = regexp.MustCompile(`^[a-z][a-z0-9_-]{0,31}$`)

// Payload is the files.manager.v1 capability's request body. Which fields
// matter depends on the OperationEnvelope's own Operation:
//   - Observe: Path only (empty means the docroot's own top level). Reports
//     either a directory listing or file content back on ResultEnvelope.Data,
//     whichever Path actually resolves to.
//   - Create: Path, IsDirectory, and (for a file) ContentBase64.
//   - Update: Path plus exactly one of ContentBase64 (overwrite) or NewPath
//     (rename/move).
//   - Delete: Path and Recursive (required true to remove a non-empty
//     directory; a plain Remove is used otherwise, which already refuses a
//     non-empty directory on its own).
type Payload struct {
	AccountUsername string `json:"account_username"`
	Path            string `json:"path"`
	NewPath         string `json:"new_path"`
	ContentBase64   string `json:"content_base64"`
	IsDirectory     bool   `json:"is_directory"`
	Recursive       bool   `json:"recursive"`
}

// ValidationError is a well-formed payload rejection: a schema-shaped
// (code, message, field) triple the caller turns directly into a rejected
// ResultEnvelope. Never a Go error representing "no verdict was reached".
type ValidationError struct {
	Code    string
	Message string
	Field   string
}

func (e *ValidationError) Error() string {
	return fmt.Sprintf("%s: %s (field=%s)", e.Code, e.Message, e.Field)
}

// maxPathLength bounds a single relative path component -- generous enough
// for any real directory structure, tight enough to reject nonsense before
// it ever reaches the filesystem.
const maxPathLength = 1024

// ParsePayload decodes and validates raw as a Payload. Unknown fields are a
// hard decode error (never silently ignored), matching every other
// capability's own ParsePayload in this project.
func ParsePayload(raw json.RawMessage) (Payload, error) {
	var p Payload

	dec := json.NewDecoder(bytes.NewReader(raw))
	dec.DisallowUnknownFields()

	if err := dec.Decode(&p); err != nil {
		return Payload{}, fmt.Errorf("decoding files payload: %w", err)
	}

	if !usernamePattern.MatchString(p.AccountUsername) {
		return Payload{}, &ValidationError{Code: "invalid_account_username", Message: "account_username is required and must be a valid system username", Field: "account_username"}
	}

	if err := validateRelativePath(p.Path, "path"); err != nil {
		return Payload{}, err
	}

	if p.NewPath != "" {
		if err := validateRelativePath(p.NewPath, "new_path"); err != nil {
			return Payload{}, err
		}
	}

	if p.ContentBase64 != "" {
		decoded, err := base64.StdEncoding.DecodeString(p.ContentBase64)
		if err != nil {
			return Payload{}, &ValidationError{Code: "invalid_content", Message: "content_base64 is not valid base64", Field: "content_base64"}
		}

		if len(decoded) > maxContentBytes {
			return Payload{}, &ValidationError{Code: "content_too_large", Message: fmt.Sprintf("content exceeds the %d byte limit", maxContentBytes), Field: "content_base64"}
		}
	}

	return p, nil
}

// validateRelativePath rejects an absolute path, a literal ".." segment
// (defense in depth -- the real containment check is
// resolveSafePath/resolveExistingSafePath's own prefix test against the
// resolved docroot, which this cannot bypass even if this check somehow
// could), a null byte, or an excessively long value, before the value ever
// reaches the filesystem.
func validateRelativePath(path, field string) error {
	if len(path) > maxPathLength {
		return &ValidationError{Code: "path_too_long", Message: fmt.Sprintf("%s exceeds %d characters", field, maxPathLength), Field: field}
	}

	if strings.ContainsRune(path, 0) {
		return &ValidationError{Code: "invalid_path", Message: field + " contains a null byte", Field: field}
	}

	if strings.HasPrefix(path, "/") {
		return &ValidationError{Code: "invalid_path", Message: field + " must be relative, not absolute", Field: field}
	}

	for _, segment := range strings.Split(path, "/") {
		if segment == ".." {
			return &ValidationError{Code: "invalid_path", Message: field + " may not contain a \"..\" segment", Field: field}
		}
	}

	return nil
}
