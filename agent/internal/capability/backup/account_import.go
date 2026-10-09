package backup

import (
	"archive/tar"
	"compress/gzip"
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
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"syscall"
	"time"

	"github.com/mikho/LESta/agent/internal/protocol"
)

// This file brings a standard account backup (the plain tar.gz a download or an
// off-node copy produces) back onto a node as an ordinary sealed backup, which
// the existing restore then applies. Nothing from the file is trusted: it is
// copied to a private temporary file, read end to end (every entry name checked
// the way a restore checks it, the manifest required first and required to name
// this very account), and only then sealed under a fresh key. A backup made for
// another account is refused, so an import cannot be used to move data between
// accounts.

var importPathPattern = regexp.MustCompile(`^/agent/v1/account-backup-imports/[0-9a-f]{64}$`)

const (
	// maxImportBytes is the largest compressed file accepted (the same 4 GB as a download).
	maxImportBytes int64 = 4 << 30
	// maxImportExpandedBytes bounds what the file may expand to, against a compression bomb.
	maxImportExpandedBytes int64 = 20 << 30
	importTimeout                = 2 * time.Hour
)

// importSourceClient is the HTTP client used to fetch the file from the panel.
func (c Config) importSourceClient() *http.Client {
	if c.UploadClient != nil {
		return c.UploadClient
	}

	return &http.Client{Timeout: importTimeout}
}

// fetchImport copies the source into tmp: from the panel's one-time address, or
// from the account's own storage with a signed GET.
func fetchImport(ctx context.Context, cfg Config, req accountRequest, tmp io.Writer) error {
	var httpReq *http.Request

	var client *http.Client

	if req.SourceURL != "" {
		target, err := url.Parse(req.SourceURL)
		if err != nil || (target.Scheme != "https" && target.Scheme != "http") || target.Host == "" || target.RawQuery != "" || !importPathPattern.MatchString(target.Path) {
			return errors.New("the source address is not a one-time backup import address")
		}

		httpReq, err = http.NewRequestWithContext(ctx, http.MethodGet, target.String(), nil)
		if err != nil {
			return err
		}

		client = cfg.importSourceClient()
	} else {
		if err := validateS3(req.Destination, req.ObjectKey); err != nil {
			return err
		}

		endpoint, err := url.Parse(req.Destination.Endpoint)
		if err != nil {
			return err
		}

		target := *endpoint
		target.Path = "/" + req.Destination.Bucket + "/" + req.ObjectKey
		target.RawPath = ""

		now := time.Now()
		emptyHash := hex.EncodeToString(sha256.New().Sum(nil))

		httpReq, err = http.NewRequestWithContext(ctx, http.MethodGet, target.String(), nil)
		if err != nil {
			return err
		}

		httpReq.Header.Set("x-amz-content-sha256", emptyHash)
		httpReq.Header.Set("x-amz-date", now.UTC().Format("20060102T150405Z"))
		signV4(httpReq, []string{"host", "x-amz-content-sha256", "x-amz-date"}, emptyHash, req.Destination.AccessKey, req.Destination.SecretKey, req.Destination.Region, "s3", now)

		client = cfg.StorageClient
		if client == nil {
			client = storageClient(cfg.AllowPrivateStorage)
		}
	}

	resp, err := client.Do(httpReq)
	if err != nil {
		return fmt.Errorf("the backup file could not be fetched: %w", err)
	}

	defer func() { _ = resp.Body.Close() }()

	if resp.StatusCode/100 != 2 {
		msg, _ := io.ReadAll(io.LimitReader(resp.Body, 512))

		return fmt.Errorf("the source refused the request (%s): %s", strconv.Itoa(resp.StatusCode), strings.TrimSpace(string(msg)))
	}

	n, err := io.Copy(tmp, io.LimitReader(resp.Body, maxImportBytes+1))
	if err != nil {
		return fmt.Errorf("fetching the backup file: %w", err)
	}

	if n > maxImportBytes {
		return errors.New("the backup file is larger than the 4 GB import limit")
	}

	if n == 0 {
		return errors.New("the backup file is empty")
	}

	return nil
}

// validateImport reads the whole tar.gz and returns what it holds. The first
// entry must be the manifest, naming this account; every other entry must have a
// name a restore would accept.
func validateImport(r io.Reader, username string) (archiveReport, error) {
	report := archiveReport{Parts: map[string]partReport{}}

	gz, err := gzip.NewReader(r)
	if err != nil {
		return report, errors.New("the file is not a gzip archive")
	}

	tr := tar.NewReader(gz)

	header, err := tr.Next()
	if err != nil || header.Name != manifestName || header.Size > 1<<20 {
		return report, errors.New("the file is not a LESta account backup (no manifest first)")
	}

	raw, err := io.ReadAll(io.LimitReader(tr, 1<<20))
	if err != nil {
		return report, errors.New("the manifest could not be read")
	}

	var manifest accountManifest
	if err := json.Unmarshal(raw, &manifest); err != nil || manifest.Version != 1 {
		return report, errors.New("the manifest is not a supported LESta backup manifest")
	}

	if manifest.Username != username {
		return report, errors.New("this backup was made for a different account and cannot be imported here")
	}

	var expanded int64

	for {
		header, err = tr.Next()
		if errors.Is(err, io.EOF) {
			break
		}

		if err != nil {
			return report, fmt.Errorf("the archive is damaged: %w", err)
		}

		part, _, _, ok := splitEntryName(header.Name)
		if !ok || part == "" {
			return report, errors.New("the archive holds an entry with an unsafe name")
		}

		switch header.Typeflag {
		case tar.TypeReg, tar.TypeDir, tar.TypeSymlink:
		default:
			return report, errors.New("the archive holds an entry of a kind a backup never contains")
		}

		expanded += header.Size
		if expanded > maxImportExpandedBytes {
			return report, errors.New("the archive expands to more than the import limit")
		}

		p := report.Parts[part]
		p.Entries++
		p.Bytes += header.Size
		report.Parts[part] = p
	}

	// Reading to the end verifies the gzip checksum.
	if _, err := io.Copy(io.Discard, gz); err != nil {
		return report, fmt.Errorf("the archive is damaged: %w", err)
	}

	if len(report.Parts) == 0 {
		return report, errors.New("the archive holds no files, databases or mail")
	}

	return report, nil
}

// applyAccountImport fetches, checks and seals a backup under a new key.
func applyAccountImport(ctx context.Context, cfg Config, req accountRequest, artifact string) accountResponse {
	dir := filepath.Dir(artifact)

	info, err := os.Stat(dir)
	if err != nil || !info.IsDir() {
		return accountError("artifacts_root_unavailable", "the account's backup folder does not exist")
	}

	if free := freeBytes(dir); free < minFreeBytes {
		return accountError("not_enough_space", fmt.Sprintf("this node has only %d MB free for backups; at least %d MB is needed", free>>20, minFreeBytes>>20))
	}

	tmpPath := filepath.Join(dir, ".import-"+filepath.Base(artifact)+".tmp")

	tmp, err := os.OpenFile(tmpPath, os.O_RDWR|os.O_CREATE|os.O_TRUNC|syscall.O_NOFOLLOW, 0o600)
	if err != nil {
		return accountError("artifact_write_failed", err.Error())
	}

	defer func() {
		_ = tmp.Close()
		_ = os.Remove(tmpPath)
	}()

	ctx, cancel := context.WithTimeout(ctx, importTimeout)
	defer cancel()

	if err := fetchImport(ctx, cfg, req, tmp); err != nil {
		return accountError("import_fetch_failed", err.Error())
	}

	if _, err := tmp.Seek(0, io.SeekStart); err != nil {
		return accountError("import_failed", err.Error())
	}

	report, err := validateImport(tmp, req.Account.Username)
	if err != nil {
		return accountError("import_invalid", err.Error())
	}

	if _, err := tmp.Seek(0, io.SeekStart); err != nil {
		return accountError("import_failed", err.Error())
	}

	partial := artifact + ".partial"

	out, err := os.OpenFile(partial, os.O_WRONLY|os.O_CREATE|os.O_TRUNC|syscall.O_NOFOLLOW, 0o600)
	if err != nil {
		return accountError("artifact_write_failed", err.Error())
	}

	hasher := sha256.New()
	size := &countingWriter{}

	err = sealCopy(io.MultiWriter(out, hasher, size), req.EncryptionKey, tmp)
	if closeErr := out.Close(); err == nil {
		err = closeErr
	}

	if err != nil {
		_ = os.Remove(partial)

		return accountError("artifact_write_failed", err.Error())
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

// sealCopy writes everything from r into w as one sealed stream.
func sealCopy(w io.Writer, hexKey string, r io.Reader) error {
	sw, err := newSealWriter(w, hexKey)
	if err != nil {
		return err
	}

	if _, err := io.Copy(sw, r); err != nil {
		return err
	}

	return sw.Close()
}

// applyAccountImportOp is the daemon's half of an import: a create carrying a
// source (the panel's one-time address, or the account's storage and an object
// key). The sealed result is reported like any other account backup.
func (c *BackupCapability) applyAccountImportOp(ctx context.Context, op protocol.OperationEnvelope, payload Payload) (protocol.ResultEnvelope, error) {
	if payload.SourceURL != nil && payload.ObjectKey != nil {
		return c.rejected(op, "invalid_import_source", "an import names one source: an address or an object in storage", "source_url")
	}

	if payload.SourceURL == nil {
		if err := validateS3(payload.Destination, derefString(payload.ObjectKey)); err != nil {
			return c.rejectedFromValidationError(op, err)
		}
	}

	dir := c.cfg.accountArtifactDir(payload.Account.Username)
	artifact := filepath.Join(dir, op.ResourceID+".acct.enc")

	if _, err := os.Stat(artifact); err == nil {
		checksum, hashErr := fileChecksum(artifact)
		if hashErr != nil {
			return c.failed(op, "artifact_read_failed", hashErr.Error())
		}

		info, _ := os.Stat(artifact)
		data, _ := json.Marshal(accountCreateData{Parts: payload.Account.Parts, SizeBytes: info.Size(), Checksum: checksum, ArtifactPath: artifact})

		result := c.buildResult(op, protocol.StatusAlreadyApplied, nil)
		result.Data = data
		result.ObservedStateDigest = checksum

		return result, nil
	}

	if err := os.MkdirAll(dir, 0o750); err != nil {
		return c.failed(op, "artifacts_root_unavailable", err.Error())
	}

	req := accountRequest{Verb: "import", ArtifactPath: artifact, EncryptionKey: *payload.EncryptionKey, Account: *payload.Account, Destination: payload.Destination, ObjectKey: derefString(payload.ObjectKey), SourceURL: derefString(payload.SourceURL)}

	resp, err := c.runAccountHelper(ctx, req)
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

func derefString(s *string) string {
	if s == nil {
		return ""
	}

	return *s
}
