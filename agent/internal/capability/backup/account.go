package backup

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"os/exec"
	"os/user"
	"path/filepath"
	"regexp"
	"strconv"
	"syscall"
	"time"

	"github.com/mikho/LESta/agent/internal/protocol"
)

// This file is the per-account (tenant) mode of backup.encrypted-artifacts.v1.
// A payload with an "account" object backs up, or restores, only that
// account's own site folders, mailboxes and databases, into a sealed archive
// under <ArtifactsRoot>/accounts/<username>/. The control plane decides which
// resources belong to the account; this node re-validates every name and builds
// every path itself, so a payload cannot name a path.
//
// The real work (reading tenant folders and mailboxes, dumping and loading
// databases) needs root, so the daemon hands it to this binary's own
// "backup-account-apply" CLI mode through sudo, the same pattern as
// files-manager-apply.

var (
	accountUsernamePattern = regexp.MustCompile(`^[a-z][a-z0-9_-]{0,31}$`)
	accountResourcePattern = regexp.MustCompile(`^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$`)
	accountDomainPattern   = regexp.MustCompile(`^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$`)
	accountDatabasePattern = regexp.MustCompile(`^[A-Za-z0-9_]{1,64}$`)
)

const (
	maxAccountItems = 200
	// minFreeBytes is the free space the artifacts directory must have before a
	// backup starts.
	minFreeBytes uint64 = 1 << 30
)

// AccountPayload names the account and the resources a backup covers or a
// restore may touch. Parts selects what is backed up or restored.
type AccountPayload struct {
	Username     string   `json:"username"`
	WebResources []string `json:"web_resources"`
	MailDomains  []string `json:"mail_domains"`
	Databases    []string `json:"databases"`
	Parts        []string `json:"parts"`
}

func validateAccountPayload(a *AccountPayload) error {
	if a == nil {
		return nil
	}

	fail := func(field, message string) error {
		return &ValidationError{Code: "invalid_account", Message: message, Field: "account." + field}
	}

	if !accountUsernamePattern.MatchString(a.Username) {
		return fail("username", "username must be a valid system username")
	}

	if len(a.WebResources) > maxAccountItems || len(a.MailDomains) > maxAccountItems || len(a.Databases) > maxAccountItems {
		return fail("web_resources", fmt.Sprintf("at most %d items of each kind", maxAccountItems))
	}

	for _, id := range a.WebResources {
		if !accountResourcePattern.MatchString(id) {
			return fail("web_resources", "a web resource id is not a valid UUID")
		}
	}

	for _, d := range a.MailDomains {
		if !accountDomainPattern.MatchString(d) {
			return fail("mail_domains", "a mail domain is not a valid domain name")
		}
	}

	for _, db := range a.Databases {
		if !accountDatabasePattern.MatchString(db) {
			return fail("databases", "a database name has characters other than letters, numbers and underscores")
		}
	}

	if len(a.Parts) == 0 {
		return fail("parts", "choose at least one part")
	}

	for _, part := range a.Parts {
		if part != partFiles && part != partDatabases && part != partMail {
			return fail("parts", "a part must be files, databases or mail")
		}
	}

	return nil
}

func (a AccountPayload) wants(part string) bool {
	for _, p := range a.Parts {
		if p == part {
			return true
		}
	}

	return false
}

// accountArtifactDir is where one account's backups live.
func (c Config) accountArtifactDir(username string) string {
	return filepath.Join(c.ArtifactsRoot, "accounts", username)
}

// accountRequest is the one JSON object piped to the root helper.
type accountRequest struct {
	Verb          string         `json:"verb"`
	ArtifactPath  string         `json:"artifact_path"`
	EncryptionKey string         `json:"encryption_key"`
	Account       AccountPayload `json:"account"`
	UploadURL     string         `json:"upload_url,omitempty"`
	Destination   *S3Destination `json:"destination,omitempty"`
	ObjectKey     string         `json:"object_key,omitempty"`
}

// accountResponse is the one JSON object the helper writes.
type accountResponse struct {
	OK      bool            `json:"ok"`
	Code    string          `json:"code,omitempty"`
	Message string          `json:"message,omitempty"`
	Data    json.RawMessage `json:"data,omitempty"`
}

func accountError(code, message string) accountResponse {
	return accountResponse{OK: false, Code: code, Message: message}
}

// accountCreateData is reported by a successful account backup.
type accountCreateData struct {
	Parts        []string      `json:"parts"`
	SizeBytes    int64         `json:"size_bytes"`
	Checksum     string        `json:"checksum"`
	ArtifactPath string        `json:"artifact_path"`
	Report       archiveReport `json:"report"`
}

// accountRestoreData is reported by a successful account restore.
type accountRestoreData struct {
	Restored []string `json:"restored"`
	Skipped  []string `json:"skipped,omitempty"`
}

// ApplyAccount is the "backup-account-apply" CLI mode: it reads one request from
// stdin and writes one response to stdout, and always exits 0 for a handled
// outcome (the response says whether it worked).
func ApplyAccount(cfg Config, stdin io.Reader, stdout io.Writer) int {
	var req accountRequest

	dec := json.NewDecoder(io.LimitReader(stdin, 1<<20))
	dec.DisallowUnknownFields()

	var resp accountResponse

	if err := dec.Decode(&req); err != nil {
		resp = accountError("invalid_request", "the request could not be read")
	} else {
		resp = applyAccount(context.Background(), cfg, req)
	}

	if err := json.NewEncoder(stdout).Encode(resp); err != nil {
		return 1
	}

	return 0
}

func applyAccount(ctx context.Context, cfg Config, req accountRequest) accountResponse {
	if err := validateAccountPayload(&req.Account); err != nil {
		return accountError("invalid_account", err.Error())
	}

	if !encryptionKeyPattern.MatchString(req.EncryptionKey) {
		return accountError("invalid_encryption_key", "the encryption key is not a 64-character hex string")
	}

	dir := cfg.accountArtifactDir(req.Account.Username)
	artifact := filepath.Clean(req.ArtifactPath)

	if filepath.Dir(artifact) != dir || filepath.Ext(artifact) != ".enc" {
		return accountError("artifact_path_outside_root", "the artifact is not in the account's own backup folder")
	}

	switch req.Verb {
	case "create":
		return applyAccountCreate(ctx, cfg, req, artifact)
	case "restore":
		return applyAccountRestore(ctx, cfg, req, artifact)
	case "download":
		return applyAccountDownload(ctx, cfg, req, artifact)
	case "copy":
		return applyAccountCopy(ctx, cfg, req, artifact)
	}

	return accountError("unsupported_verb", "the verb must be create or restore")
}

func accountIdentity(username string) (uid, gid int, err error) {
	u, err := user.Lookup(username)
	if err != nil {
		return 0, 0, fmt.Errorf("the account's system user does not exist on this node: %w", err)
	}

	if uid, err = strconv.Atoi(u.Uid); err != nil {
		return 0, 0, err
	}

	if gid, err = strconv.Atoi(u.Gid); err != nil {
		return 0, 0, err
	}

	return uid, gid, nil
}

func (c Config) docroot(username, resourceID string) string {
	return filepath.Join(c.AccountsRoot, username, "domains", resourceID, "public")
}

// asAccount returns the hook that runs a function under the account's own
// filesystem identity.
func asAccount(uid, gid int) func(fn func() error) error {
	return func(fn func() error) error { return runAsAccount(uid, gid, fn) }
}

func applyAccountCreate(ctx context.Context, cfg Config, req accountRequest, artifact string) accountResponse {
	uid, gid, err := accountIdentity(req.Account.Username)
	if err != nil {
		return accountError("account_unknown", err.Error())
	}

	dir := filepath.Dir(artifact)

	info, err := os.Stat(dir)
	if err != nil || !info.IsDir() {
		return accountError("artifacts_root_unavailable", "the account's backup folder does not exist")
	}

	if free := freeBytes(dir); free < minFreeBytes {
		return accountError("not_enough_space", fmt.Sprintf("this node has only %d MB free for backups; at least %d MB is needed", free>>20, minFreeBytes>>20))
	}

	src := archiveSource{Username: req.Account.Username, AsAccount: asAccount(uid, gid), MaxBytes: cfg.MaxAccountSourceBytes}

	var dumps []string

	defer func() {
		for _, p := range dumps {
			_ = os.Remove(p)
		}
	}()

	if req.Account.wants(partDatabases) {
		socket := cfg.DatabaseDumpSockets[databaseTenantCapability]
		if len(req.Account.Databases) > 0 && !socketExists(socket) {
			return accountError("database_unavailable", "this node's tenant database server is not running")
		}

		for _, name := range req.Account.Databases {
			out := filepath.Join(dir, ".dump-"+filepath.Base(artifact)+"-"+name+".sql")
			dumps = append(dumps, out)

			if err := dumpOneDatabase(ctx, cfg, socket, name, out); err != nil {
				return accountError("database_dump_failed", err.Error())
			}

			src.Databases = append(src.Databases, dbDump{Name: name, Path: out})
		}
	}

	if req.Account.wants(partMail) && cfg.VMailRoot != "" {
		src.MailDirs = map[string]string{}
		for _, d := range req.Account.MailDomains {
			src.MailDirs[d] = filepath.Join(cfg.VMailRoot, d)
		}
	}

	if req.Account.wants(partFiles) {
		src.DocRoots = map[string]string{}
		for _, id := range req.Account.WebResources {
			src.DocRoots[id] = cfg.docroot(req.Account.Username, id)
		}
	}

	partial := artifact + ".partial"

	f, err := os.OpenFile(partial, os.O_WRONLY|os.O_CREATE|os.O_TRUNC|syscall.O_NOFOLLOW, 0o600)
	if err != nil {
		return accountError("artifact_write_failed", err.Error())
	}

	hasher := sha256.New()
	size := &countingWriter{}

	report, err := writeAccountArchive(io.MultiWriter(f, hasher, size), req.EncryptionKey, src)
	if closeErr := f.Close(); err == nil {
		err = closeErr
	}

	if err != nil {
		_ = os.Remove(partial)

		if errors.Is(err, ErrBackupTooLarge) {
			return accountError("backup_too_large", "the account's data is larger than the backup size limit")
		}

		return accountError("archive_failed", err.Error())
	}

	if err := os.Chown(partial, int(dirOwner(info).uid), int(dirOwner(info).gid)); err != nil && os.Geteuid() == 0 {
		_ = os.Remove(partial)

		return accountError("artifact_write_failed", err.Error())
	}

	if err := os.Rename(partial, artifact); err != nil {
		_ = os.Remove(partial)

		return accountError("artifact_write_failed", err.Error())
	}

	data, _ := json.Marshal(accountCreateData{
		Parts:        presentParts(report),
		SizeBytes:    size.n,
		Checksum:     "sha256:" + hex.EncodeToString(hasher.Sum(nil)),
		ArtifactPath: artifact,
		Report:       report,
	})

	return accountResponse{OK: true, Data: data}
}

func presentParts(report archiveReport) []string {
	parts := make([]string, 0, len(report.Parts))

	for _, part := range []string{partFiles, partDatabases, partMail} {
		if _, ok := report.Parts[part]; ok {
			parts = append(parts, part)
		}
	}

	return parts
}

type countingWriter struct{ n int64 }

func (c *countingWriter) Write(p []byte) (int, error) {
	c.n += int64(len(p))

	return len(p), nil
}

func applyAccountRestore(ctx context.Context, cfg Config, req accountRequest, artifact string) accountResponse {
	uid, gid, err := accountIdentity(req.Account.Username)
	if err != nil {
		return accountError("account_unknown", err.Error())
	}

	f, err := os.OpenFile(artifact, os.O_RDONLY|syscall.O_NOFOLLOW, 0)
	if errors.Is(err, os.ErrNotExist) {
		return accountError("artifact_not_found", "the backup file no longer exists on this node")
	} else if err != nil {
		return accountError("artifact_read_failed", err.Error())
	}

	defer func() { _ = f.Close() }()

	target := restoreTarget{
		Parts:     map[string]bool{},
		DocRoots:  map[string]string{},
		MailDirs:  map[string]string{},
		Databases: map[string]bool{},
		AsAccount: asAccount(uid, gid),
		Chown:     func(path string, uid, gid int) error { return os.Lchown(path, uid, gid) },
	}

	for _, part := range req.Account.Parts {
		target.Parts[part] = true
	}

	for _, id := range req.Account.WebResources {
		target.DocRoots[id] = cfg.docroot(req.Account.Username, id)
	}

	if cfg.VMailRoot != "" {
		for _, d := range req.Account.MailDomains {
			target.MailDirs[d] = filepath.Join(cfg.VMailRoot, d)
		}
	}

	socket := cfg.DatabaseDumpSockets[databaseTenantCapability]

	for _, name := range req.Account.Databases {
		target.Databases[name] = true
	}

	target.RestoreDatabase = func(name string, sql io.Reader) error {
		if !socketExists(socket) {
			return errors.New("this node's tenant database server is not running")
		}

		return loadDatabase(ctx, cfg, socket, sql)
	}

	report, err := restoreAccountArchive(f, req.EncryptionKey, target)
	if err != nil {
		return accountError("restore_failed", err.Error())
	}

	data, _ := json.Marshal(accountRestoreData{Restored: report.Restored, Skipped: report.Skipped})

	return accountResponse{OK: true, Data: data}
}

func socketExists(socket string) bool {
	if socket == "" {
		return false
	}

	_, err := os.Stat(socket)

	return err == nil
}

// dumpBinary and clientBinary are the real MariaDB binaries, or the test
// stand-ins from Config.
func (c Config) dumpBinary() string {
	if c.DumpBinary != "" {
		return c.DumpBinary
	}

	return mariadbDumpBinary()
}

func (c Config) clientBinary() string {
	if c.ClientBinary != "" {
		return c.ClientBinary
	}

	return mariadbClientBinary()
}

// dumpOneDatabase writes a consistent dump of one named database to out. The
// helper already runs as root, which the tenant instance's unix_socket login
// requires.
func dumpOneDatabase(ctx context.Context, cfg Config, socket, name, out string) error {
	f, err := os.OpenFile(out, os.O_WRONLY|os.O_CREATE|os.O_TRUNC|syscall.O_NOFOLLOW, 0o600)
	if err != nil {
		return err
	}

	defer func() { _ = f.Close() }()

	var stderr bytes.Buffer

	cmd := exec.CommandContext(ctx, cfg.dumpBinary(), "-S", socket, "-u", "root", "--single-transaction", "--routines", "--triggers", "--events", "--databases", name)
	cmd.Stdout = f
	cmd.Stderr = &stderr

	if err := cmd.Run(); err != nil {
		return fmt.Errorf("dumping %s: %w: %s", name, err, bytes.TrimSpace(stderr.Bytes()))
	}

	return nil
}

// loadDatabase feeds a dump to the tenant instance.
func loadDatabase(ctx context.Context, cfg Config, socket string, sql io.Reader) error {
	var stderr bytes.Buffer

	cmd := exec.CommandContext(ctx, cfg.clientBinary(), "-S", socket, "-u", "root")
	cmd.Stdin = sql
	cmd.Stderr = &stderr

	if err := cmd.Run(); err != nil {
		return fmt.Errorf("%w: %s", err, bytes.TrimSpace(stderr.Bytes()))
	}

	return nil
}

// ---- daemon side ---------------------------------------------------------

// runAccountHelper performs req as root: through "sudo <agent> backup-account-apply"
// in production, or in this process when no sudo is configured (tests).
func (c *BackupCapability) runAccountHelper(ctx context.Context, req accountRequest) (accountResponse, error) {
	if c.cfg.SudoBinary == "" {
		return applyAccount(ctx, c.cfg, req), nil
	}

	body, err := json.Marshal(req)
	if err != nil {
		return accountResponse{}, err
	}

	var stdout, stderr bytes.Buffer

	cmd := exec.CommandContext(ctx, c.cfg.SudoBinary, c.cfg.AgentBinaryPath, "backup-account-apply")
	cmd.Stdin = bytes.NewReader(body)
	cmd.Stdout = &stdout
	cmd.Stderr = &stderr

	if err := cmd.Run(); err != nil {
		return accountResponse{}, fmt.Errorf("running the account backup helper: %w: %s", err, bytes.TrimSpace(stderr.Bytes()))
	}

	var resp accountResponse
	if err := json.Unmarshal(stdout.Bytes(), &resp); err != nil {
		return accountResponse{}, fmt.Errorf("reading the account backup helper's answer: %w", err)
	}

	return resp, nil
}

// applyAccountCreateOp is the daemon's half of an account backup create.
func (c *BackupCapability) applyAccountCreateOp(ctx context.Context, op protocol.OperationEnvelope, payload Payload) (protocol.ResultEnvelope, error) {
	dir := c.cfg.accountArtifactDir(payload.Account.Username)
	artifact := filepath.Join(dir, op.ResourceID+".acct.enc")

	if info, err := os.Stat(artifact); err == nil {
		// An idempotent replay of a create that already finished.
		checksum, hashErr := fileChecksum(artifact)
		if hashErr != nil {
			return c.failed(op, "artifact_read_failed", hashErr.Error())
		}

		data, _ := json.Marshal(accountCreateData{Parts: payload.Account.Parts, SizeBytes: info.Size(), Checksum: checksum, ArtifactPath: artifact})

		result := c.buildResult(op, protocol.StatusAlreadyApplied, nil)
		result.Data = data
		result.ObservedStateDigest = checksum

		return result, nil
	}

	if err := os.MkdirAll(dir, 0o750); err != nil {
		return c.failed(op, "artifacts_root_unavailable", err.Error())
	}

	resp, err := c.runAccountHelper(ctx, accountRequest{Verb: "create", ArtifactPath: artifact, EncryptionKey: *payload.EncryptionKey, Account: *payload.Account})
	if err != nil {
		return c.failed(op, "account_helper_failed", err.Error())
	}

	if !resp.OK {
		return c.failed(op, resp.Code, resp.Message)
	}

	var data accountCreateData
	_ = json.Unmarshal(resp.Data, &data)

	result := c.buildResult(op, protocol.StatusApplied, nil)
	result.Data = resp.Data
	result.ObservedStateDigest = data.Checksum

	return result, nil
}

// applyAccountRestoreOp is the daemon's half of an account restore.
func (c *BackupCapability) applyAccountRestoreOp(ctx context.Context, op protocol.OperationEnvelope, payload Payload) (protocol.ResultEnvelope, error) {
	resp, err := c.runAccountHelper(ctx, accountRequest{Verb: "restore", ArtifactPath: *payload.ArtifactPath, EncryptionKey: *payload.EncryptionKey, Account: *payload.Account})
	if err != nil {
		return c.failed(op, "account_helper_failed", err.Error())
	}

	if !resp.OK {
		return c.failed(op, resp.Code, resp.Message)
	}

	result := c.buildResult(op, protocol.StatusApplied, nil)
	result.Data = resp.Data

	return result, nil
}

func fileChecksum(path string) (string, error) {
	f, err := os.Open(path)
	if err != nil {
		return "", err
	}

	defer func() { _ = f.Close() }()

	h := sha256.New()
	if _, err := io.Copy(h, f); err != nil {
		return "", err
	}

	return "sha256:" + hex.EncodeToString(h.Sum(nil)), nil
}

// ---- download -------------------------------------------------------------

var uploadPathPattern = regexp.MustCompile(`^/agent/v1/account-backup-uploads/[0-9a-f]{64}$`)

const (
	// downloadChunkSize is how much of the backup one upload request carries,
	// below the panel's request size limit.
	downloadChunkSize = 4 << 20
	downloadAttempts  = 3
)

type accountDownloadData struct {
	BytesSent int64 `json:"bytes_sent"`
}

// applyAccountDownload decrypts the account backup on this node and streams it to
// the control plane's one-time upload address as a plain tar.gz, in chunks of
// downloadChunkSize at increasing offsets, then sends an empty final chunk. The
// final chunk is sent only after the whole sealed archive authenticated, so a
// damaged or truncated backup is never offered as a complete download.
func applyAccountDownload(ctx context.Context, cfg Config, req accountRequest, artifact string) accountResponse {
	target, err := url.Parse(req.UploadURL)
	if err != nil || (target.Scheme != "https" && target.Scheme != "http") || target.Host == "" || target.RawQuery != "" || !uploadPathPattern.MatchString(target.Path) {
		return accountError("invalid_upload_url", "the upload address is not a one-time backup upload address")
	}

	f, err := os.OpenFile(artifact, os.O_RDONLY|syscall.O_NOFOLLOW, 0)
	if errors.Is(err, os.ErrNotExist) {
		return accountError("artifact_not_found", "the backup file no longer exists on this node")
	} else if err != nil {
		return accountError("artifact_read_failed", err.Error())
	}

	defer func() { _ = f.Close() }()

	sr, err := newSealReader(f, req.EncryptionKey)
	if err != nil {
		return accountError("decryption_failed", err.Error())
	}

	client := cfg.uploadClient()
	buf := make([]byte, downloadChunkSize)

	var offset int64

	for {
		n, readErr := io.ReadFull(sr, buf)
		if n > 0 {
			if err := uploadChunk(ctx, client, target, offset, false, buf[:n]); err != nil {
				return accountError("upload_failed", err.Error())
			}

			offset += int64(n)
		}

		if errors.Is(readErr, io.EOF) || errors.Is(readErr, io.ErrUnexpectedEOF) {
			break
		}

		if readErr != nil {
			return accountError("decryption_failed", readErr.Error())
		}
	}

	if err := uploadChunk(ctx, client, target, offset, true, nil); err != nil {
		return accountError("upload_failed", err.Error())
	}

	data, _ := json.Marshal(accountDownloadData{BytesSent: offset})

	return accountResponse{OK: true, Data: data}
}

func (c Config) uploadClient() *http.Client {
	if c.UploadClient != nil {
		return c.UploadClient
	}

	return &http.Client{Timeout: 2 * time.Minute}
}

// uploadChunk PUTs one chunk at offset, retrying a failed attempt a few times.
func uploadChunk(ctx context.Context, client *http.Client, base *url.URL, offset int64, final bool, body []byte) error {
	target := *base
	query := url.Values{"offset": {strconv.FormatInt(offset, 10)}}

	if final {
		query.Set("final", "1")
	}

	target.RawQuery = query.Encode()

	var lastErr error

	for attempt := 1; attempt <= downloadAttempts; attempt++ {
		req, err := http.NewRequestWithContext(ctx, http.MethodPut, target.String(), bytes.NewReader(body))
		if err != nil {
			return err
		}

		req.Header.Set("Content-Type", "application/octet-stream")

		resp, err := client.Do(req)
		if err == nil {
			msg, _ := io.ReadAll(io.LimitReader(resp.Body, 512))
			_ = resp.Body.Close()

			if resp.StatusCode/100 == 2 {
				return nil
			}

			// A refusal (bad token, wrong offset, too large) will not change on retry.
			if resp.StatusCode/100 == 4 {
				return fmt.Errorf("the control plane refused the upload (%d): %s", resp.StatusCode, bytes.TrimSpace(msg))
			}

			err = fmt.Errorf("the control plane answered %d", resp.StatusCode)
		}

		lastErr = err

		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(time.Duration(attempt) * 500 * time.Millisecond):
		}
	}

	return fmt.Errorf("uploading a chunk at %d failed: %w", offset, lastErr)
}

// applyAccountDownloadOp is the daemon's half of a download: the helper runs as
// root (the sealed file is private), the network request is made by it.
func (c *BackupCapability) applyAccountDownloadOp(ctx context.Context, op protocol.OperationEnvelope, payload Payload) (protocol.ResultEnvelope, error) {
	if payload.EncryptionKey == nil || !encryptionKeyPattern.MatchString(*payload.EncryptionKey) {
		return c.rejected(op, "invalid_encryption_key", "encryption_key must be a 64-character lowercase hex string", "encryption_key")
	}

	if payload.UploadURL == nil || *payload.UploadURL == "" {
		return c.rejected(op, "invalid_upload_url", "upload_url is required for a download", "upload_url")
	}

	if err := validateAccountPayload(payload.Account); err != nil {
		return c.rejectedFromValidationError(op, err)
	}

	artifact := filepath.Clean(*payload.ArtifactPath)
	if !isWithinRoot(artifact, c.cfg.ArtifactsRoot) {
		return c.rejected(op, "artifact_path_outside_root", "artifact_path is not within the owned artifacts root", "artifact_path")
	}

	resp, err := c.runAccountHelper(ctx, accountRequest{Verb: "download", ArtifactPath: artifact, EncryptionKey: *payload.EncryptionKey, Account: *payload.Account, UploadURL: *payload.UploadURL})
	if err != nil {
		return c.failed(op, "account_helper_failed", err.Error())
	}

	if !resp.OK {
		return c.failed(op, resp.Code, resp.Message)
	}

	result := c.buildResult(op, protocol.StatusApplied, nil)
	result.Data = resp.Data

	return result, nil
}

// ---- copy to the account's own storage --------------------------------------

type accountCopyData struct {
	ObjectKey string `json:"object_key"`
	Bytes     int64  `json:"bytes"`
}

// applyAccountCopy decrypts the backup into a temporary plain tar.gz next to it,
// checks that the whole sealed archive authenticated, and only then uploads the
// file to the account's storage in one signed PUT. Nothing leaves the node
// unless the backup is intact, and the temporary file is always removed.
func applyAccountCopy(ctx context.Context, cfg Config, req accountRequest, artifact string) accountResponse {
	if err := validateS3(req.Destination, req.ObjectKey); err != nil {
		return accountError("invalid_destination", err.Error())
	}

	f, err := os.OpenFile(artifact, os.O_RDONLY|syscall.O_NOFOLLOW, 0)
	if errors.Is(err, os.ErrNotExist) {
		return accountError("artifact_not_found", "the backup file no longer exists on this node")
	} else if err != nil {
		return accountError("artifact_read_failed", err.Error())
	}

	defer func() { _ = f.Close() }()

	sr, err := newSealReader(f, req.EncryptionKey)
	if err != nil {
		return accountError("decryption_failed", err.Error())
	}

	tmpPath := filepath.Join(filepath.Dir(artifact), ".copy-"+filepath.Base(artifact)+".tmp")

	tmp, err := os.OpenFile(tmpPath, os.O_WRONLY|os.O_CREATE|os.O_TRUNC|syscall.O_NOFOLLOW, 0o600)
	if err != nil {
		return accountError("artifact_write_failed", err.Error())
	}

	defer func() { _ = os.Remove(tmpPath) }()

	hasher := sha256.New()

	size, copyErr := io.Copy(io.MultiWriter(tmp, hasher), sr)
	if closeErr := tmp.Close(); copyErr == nil {
		copyErr = closeErr
	}

	if copyErr != nil {
		return accountError("decryption_failed", copyErr.Error())
	}

	client := cfg.StorageClient
	if client == nil {
		client = storageClient(cfg.AllowPrivateStorage)
	}

	ctx, cancel := context.WithTimeout(ctx, 2*time.Hour)
	defer cancel()

	if err := s3Put(ctx, client, *req.Destination, req.ObjectKey, tmpPath, size, hex.EncodeToString(hasher.Sum(nil)), time.Now()); err != nil {
		return accountError("copy_failed", err.Error())
	}

	data, _ := json.Marshal(accountCopyData{ObjectKey: req.ObjectKey, Bytes: size})

	return accountResponse{OK: true, Data: data}
}

// applyAccountCopyOp is the daemon's half of a copy: an update of a per-account
// backup carrying a destination.
func (c *BackupCapability) applyAccountCopyOp(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
	p, err := decode(op.Payload)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if p.Account == nil || p.Destination == nil || p.ObjectKey == nil || p.ArtifactPath == nil || p.EncryptionKey == nil {
		return c.rejected(op, "unsupported_operation", "update is only supported for copying a per-account backup to its storage", "")
	}

	if !encryptionKeyPattern.MatchString(*p.EncryptionKey) {
		return c.rejected(op, "invalid_encryption_key", "encryption_key must be a 64-character lowercase hex string", "encryption_key")
	}

	if err := validateAccountPayload(p.Account); err != nil {
		return c.rejectedFromValidationError(op, err)
	}

	if err := validateS3(p.Destination, *p.ObjectKey); err != nil {
		return c.rejectedFromValidationError(op, err)
	}

	artifact := filepath.Clean(*p.ArtifactPath)
	if !isWithinRoot(artifact, c.cfg.ArtifactsRoot) {
		return c.rejected(op, "artifact_path_outside_root", "artifact_path is not within the owned artifacts root", "artifact_path")
	}

	resp, err := c.runAccountHelper(ctx, accountRequest{Verb: "copy", ArtifactPath: artifact, EncryptionKey: *p.EncryptionKey, Account: *p.Account, Destination: p.Destination, ObjectKey: *p.ObjectKey})
	if err != nil {
		return c.failed(op, "account_helper_failed", err.Error())
	}

	if !resp.OK {
		return c.failed(op, resp.Code, resp.Message)
	}

	result := c.buildResult(op, protocol.StatusApplied, nil)
	result.Data = resp.Data

	return result, nil
}
