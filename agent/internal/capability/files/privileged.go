package files

import (
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"os/user"
	"regexp"
	"strconv"
	"time"
)

// resourceIDPattern is a strict UUID shape, mirroring cron's own
// resourceIDPattern exactly: re-validated here from scratch since a value
// reaching Apply crosses a real process boundary (sudo, running as root),
// not just an in-process call.
var resourceIDPattern = regexp.MustCompile(`^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$`)

// applyRequest is the one JSON object piped over stdin to the real
// "files-manager-apply" CLI mode -- never split across argv, since file
// content can be arbitrarily large and path values are tenant-supplied.
type applyRequest struct {
	Verb            string `json:"verb"`
	AccountUsername string `json:"account_username"`
	ResourceID      string `json:"resource_id"`
	Path            string `json:"path"`
	NewPath         string `json:"new_path"`
	ContentBase64   string `json:"content_base64"`
	IsDirectory     bool   `json:"is_directory"`
	Recursive       bool   `json:"recursive"`
}

// applyResponse is the one JSON object the CLI mode writes to stdout.
type applyResponse struct {
	OK      bool            `json:"ok"`
	Code    string          `json:"code,omitempty"`
	Message string          `json:"message,omitempty"`
	Data    json.RawMessage `json:"data,omitempty"`
}

func errResponse(code, message string) applyResponse {
	return applyResponse{OK: false, Code: code, Message: message}
}

// pathErrorResponse classifies a resolveSafePath/resolveExistingSafePath/
// resolveSafeParent error: a deliberate traversal/symlink-escape attempt
// gets its own distinct code (useful for audit/alerting), anything else
// (the path genuinely doesn't exist, a permission error, etc.) is reported
// as not_found, never leaking the real underlying filesystem error message
// verbatim for this one class, since EvalSymlinks' own error text can
// otherwise echo back the resolved absolute path.
func pathErrorResponse(err error) applyResponse {
	if errors.Is(err, errPathEscapesDocroot) {
		return errResponse("path_escapes_docroot", "the resolved path is outside this domain's own docroot")
	}

	return errResponse("not_found", "the path does not exist")
}

// lookupAccountIdentity resolves username's own real uid/gid, the same
// identity SFTP and PHP-FPM already run as.
func lookupAccountIdentity(username string) (uid, gid int, err error) {
	u, err := user.Lookup(username)
	if err != nil {
		return 0, 0, fmt.Errorf("looking up uid/gid for %s: %w", username, err)
	}

	uid, err = strconv.Atoi(u.Uid)
	if err != nil {
		return 0, 0, fmt.Errorf("parsing uid %q for %s: %w", u.Uid, username, err)
	}

	gid, err = strconv.Atoi(u.Gid)
	if err != nil {
		return 0, 0, fmt.Errorf("parsing gid %q for %s: %w", u.Gid, username, err)
	}

	return uid, gid, nil
}

// dirEntry is one entry in an observeDirectoryData listing.
type dirEntry struct {
	Name    string `json:"name"`
	Type    string `json:"type"`
	Size    int64  `json:"size"`
	ModTime string `json:"mod_time"`
}

type observeDirectoryData struct {
	Type    string     `json:"type"`
	Entries []dirEntry `json:"entries"`
}

type observeFileData struct {
	Type          string `json:"type"`
	Size          int64  `json:"size"`
	ModTime       string `json:"mod_time"`
	ContentBase64 string `json:"content_base64"`
}

// applyObserve lists a directory or reads a file's own content, whichever
// req.Path actually resolves to -- the capability-agnostic "observe this
// resource's current state" semantics every other capability already
// implements, applied here to a filesystem path instead of a rendered
// config.
func applyObserve(cfg Config, req applyRequest) applyResponse {
	docroot := cfg.docroot(req.AccountUsername, req.ResourceID)

	resolved, err := resolveExistingSafePath(docroot, req.Path)
	if err != nil {
		return pathErrorResponse(err)
	}

	kind, info, err := statType(resolved)
	if err != nil {
		return errResponse("not_found", "the path does not exist")
	}

	switch kind {
	case "directory":
		entries, err := os.ReadDir(resolved)
		if err != nil {
			return errResponse("read_failed", err.Error())
		}

		out := make([]dirEntry, 0, len(entries))

		for _, e := range entries {
			fi, err := e.Info()
			if err != nil {
				continue
			}

			entryType := "file"
			if e.IsDir() {
				entryType = "directory"
			}

			out = append(out, dirEntry{Name: e.Name(), Type: entryType, Size: fi.Size(), ModTime: fi.ModTime().UTC().Format(time.RFC3339)})
		}

		data, err := json.Marshal(observeDirectoryData{Type: "directory", Entries: out})
		if err != nil {
			return errResponse("encode_failed", err.Error())
		}

		return applyResponse{OK: true, Data: data}
	case "file":
		if info.Size() > maxContentBytes {
			return errResponse("content_too_large", fmt.Sprintf("file exceeds the %d byte read limit", maxContentBytes))
		}

		content, err := os.ReadFile(resolved)
		if err != nil {
			return errResponse("read_failed", err.Error())
		}

		data, err := json.Marshal(observeFileData{
			Type:          "file",
			Size:          info.Size(),
			ModTime:       info.ModTime().UTC().Format(time.RFC3339),
			ContentBase64: base64.StdEncoding.EncodeToString(content),
		})
		if err != nil {
			return errResponse("encode_failed", err.Error())
		}

		return applyResponse{OK: true, Data: data}
	default:
		return errResponse("unsupported_type", "this path is neither a regular file nor a directory")
	}
}

// applyCreate makes a new, empty-or-populated file or a new directory,
// then chowns it to the account's own uid/gid -- a freshly root-created
// path would otherwise land owned by root, breaking the "identical
// ownership to the SFTP path" requirement this whole capability exists to
// uphold.
func applyCreate(cfg Config, req applyRequest) applyResponse {
	docroot := cfg.docroot(req.AccountUsername, req.ResourceID)

	_, target, err := resolveSafeParent(docroot, req.Path)
	if err != nil {
		return pathErrorResponse(err)
	}

	if _, err := os.Lstat(target); err == nil {
		return errResponse("already_exists", "a file or directory already exists at this path")
	}

	uid, gid, err := lookupAccountIdentity(req.AccountUsername)
	if err != nil {
		return errResponse("identity_lookup_failed", err.Error())
	}

	if req.IsDirectory {
		if err := os.Mkdir(target, 0o755); err != nil {
			return errResponse("mkdir_failed", err.Error())
		}

		_ = os.Chmod(target, 0o755)

		if err := os.Chown(target, uid, gid); err != nil {
			return errResponse("chown_failed", err.Error())
		}

		return applyResponse{OK: true}
	}

	content := []byte{}

	if req.ContentBase64 != "" {
		content, err = base64.StdEncoding.DecodeString(req.ContentBase64)
		if err != nil {
			return errResponse("invalid_content", "content_base64 is not valid base64")
		}
	}

	if err := os.WriteFile(target, content, 0o644); err != nil {
		return errResponse("write_failed", err.Error())
	}

	_ = os.Chmod(target, 0o644)

	if err := os.Chown(target, uid, gid); err != nil {
		return errResponse("chown_failed", err.Error())
	}

	return applyResponse{OK: true}
}

// applyUpdate overwrites an existing file's own content, or renames/moves
// it -- exactly one of req.ContentBase64 (as an explicit, non-empty
// decision; an empty file is a valid overwrite target) or req.NewPath is
// expected, enforced by the capability layer before this is ever called.
func applyUpdate(cfg Config, req applyRequest) applyResponse {
	docroot := cfg.docroot(req.AccountUsername, req.ResourceID)

	if req.NewPath != "" {
		resolvedSrc, err := resolveExistingSafePath(docroot, req.Path)
		if err != nil {
			return pathErrorResponse(err)
		}

		_, resolvedDst, err := resolveSafeParent(docroot, req.NewPath)
		if err != nil {
			return pathErrorResponse(err)
		}

		if _, err := os.Lstat(resolvedDst); err == nil {
			return errResponse("already_exists", "a file or directory already exists at the new path")
		}

		if err := os.Rename(resolvedSrc, resolvedDst); err != nil {
			return errResponse("rename_failed", err.Error())
		}

		return applyResponse{OK: true}
	}

	resolved, err := resolveExistingSafePath(docroot, req.Path)
	if err != nil {
		return pathErrorResponse(err)
	}

	kind, _, err := statType(resolved)
	if err != nil {
		return errResponse("not_found", "the path does not exist")
	}

	if kind != "file" {
		return errResponse("not_a_file", "only a regular file's own content can be overwritten")
	}

	content, err := base64.StdEncoding.DecodeString(req.ContentBase64)
	if err != nil {
		return errResponse("invalid_content", "content_base64 is not valid base64")
	}

	if err := os.WriteFile(resolved, content, 0o644); err != nil {
		return errResponse("write_failed", err.Error())
	}

	return applyResponse{OK: true}
}

// applyDelete removes a file, or a directory (recursively only when
// req.Recursive is explicitly true -- a plain os.Remove refuses a
// non-empty directory on its own, a real safety default this preserves
// rather than working around).
func applyDelete(cfg Config, req applyRequest) applyResponse {
	// Checked against req.Path directly, never the resolved path: an empty
	// relPath is resolveSafePath's own documented contract for "the
	// docroot itself", and comparing resolved (symlink-followed) strings
	// instead is fragile -- confirmed for real on macOS, where docroot's
	// own ancestry already includes a symlink.
	if req.Path == "" {
		return errResponse("forbidden", "the domain's own docroot itself cannot be deleted")
	}

	docroot := cfg.docroot(req.AccountUsername, req.ResourceID)

	resolved, err := resolveExistingSafePath(docroot, req.Path)
	if err != nil {
		return pathErrorResponse(err)
	}

	kind, _, err := statType(resolved)
	if err != nil {
		return errResponse("not_found", "the path does not exist")
	}

	if kind == "directory" && req.Recursive {
		if err := os.RemoveAll(resolved); err != nil {
			return errResponse("delete_failed", err.Error())
		}

		return applyResponse{OK: true}
	}

	if err := os.Remove(resolved); err != nil {
		return errResponse("delete_failed", err.Error())
	}

	return applyResponse{OK: true}
}

// dispatch routes req to the one real operation its own Verb names. No
// idempotency-receipt wrapper, unlike every config-rendering capability:
// every operation here is already naturally safe to retry on its own
// (create/rename reject an already-existing target rather than silently
// clobbering it; delete/observe on an already-gone path report a plain
// not_found; update's own overwrite is idempotent by construction), so a
// redelivered request never needs deduplicating against a prior one.
func dispatch(cfg Config, req applyRequest) applyResponse {
	switch req.Verb {
	case "observe":
		return applyObserve(cfg, req)
	case "create":
		return applyCreate(cfg, req)
	case "update":
		return applyUpdate(cfg, req)
	case "delete":
		return applyDelete(cfg, req)
	default:
		return errResponse("unsupported_verb", fmt.Sprintf("verb %q is not supported", req.Verb))
	}
}

// applyPrivileged runs dispatch directly (cfg.SudoBinary empty) or, in
// production, via this same binary's own "files-manager-apply" CLI mode
// under sudo, the request piped over stdin and the response read back from
// stdout -- mirroring identity.ensureChrootTreePrivileged/
// nginx.ensureDocrootPrivileged exactly, for the identical reason: a real
// chown to an arbitrary account's own uid/gid requires real root, which
// sudo cannot grant to an in-process Go syscall the way it can a separate
// exec.Command invocation.
func applyPrivileged(ctx context.Context, cfg Config, req applyRequest) (applyResponse, error) {
	if cfg.SudoBinary == "" || cfg.AgentBinaryPath == "" {
		return dispatch(cfg, req), nil
	}

	body, err := json.Marshal(req)
	if err != nil {
		return applyResponse{}, fmt.Errorf("marshaling files-manager-apply request: %w", err)
	}

	cmd := cfg.command(ctx)
	cmd.Stdin = bytes.NewReader(body)

	var stdout, stderr bytes.Buffer
	cmd.Stdout = &stdout
	cmd.Stderr = &stderr

	if err := cmd.Run(); err != nil {
		return applyResponse{}, fmt.Errorf("files-manager-apply via sudo: %w: %s", err, bytes.TrimSpace(stderr.Bytes()))
	}

	var resp applyResponse
	if err := json.Unmarshal(stdout.Bytes(), &resp); err != nil {
		return applyResponse{}, fmt.Errorf("parsing files-manager-apply response: %w: %s", err, bytes.TrimSpace(stdout.Bytes()))
	}

	return resp, nil
}

// Apply is this CLI mode's own entry point (see cmd/lesta-agent/main.go's
// own "files-manager-apply" dispatch), invoked only via the narrowly-scoped
// sudoers rule agent-daemon/install.sh writes. Reads one JSON applyRequest
// from r, writes one JSON applyResponse to w, and returns a process exit
// code (0 only when the response's own OK is true).
func Apply(cfg Config, r io.Reader, w io.Writer) int {
	var req applyRequest

	if err := json.NewDecoder(r).Decode(&req); err != nil {
		fmt.Fprintln(os.Stderr, "files-manager-apply: decoding request:", err)

		return 1
	}

	if !usernamePattern.MatchString(req.AccountUsername) {
		fmt.Fprintf(os.Stderr, "files-manager-apply: %q is not a valid account_username\n", req.AccountUsername)

		return 1
	}

	if !resourceIDPattern.MatchString(req.ResourceID) {
		fmt.Fprintf(os.Stderr, "files-manager-apply: %q is not a valid resource id\n", req.ResourceID)

		return 1
	}

	resp := dispatch(cfg, req)

	if err := json.NewEncoder(w).Encode(resp); err != nil {
		fmt.Fprintln(os.Stderr, "files-manager-apply: encoding response:", err)

		return 1
	}

	if !resp.OK {
		return 1
	}

	return 0
}
