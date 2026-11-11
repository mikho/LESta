package backup

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"hash"
	"io"
	"os"
	"path/filepath"
)

// The whole-node backup is written as a stream: tar, gzip, the chunked AES-GCM
// sealing of stream.go, a hash that sees the bytes as they land, and the file.
// Nothing larger than one chunk is held in memory, so the size of the node's
// mailboxes and databases is bounded by its disk, not by its memory. Artifacts
// written before 1.0.91 are one AES-GCM message (crypto.go); Decrypt and the
// restore path still read them, and tell the two apart by the stream magic.

// countingHash passes bytes on to w while counting and hashing them.
type countingHash struct {
	w io.Writer
	h hash.Hash
	n int64
}

func newCountingHash(w io.Writer) *countingHash {
	return &countingHash{w: w, h: sha256.New()}
}

func (c *countingHash) Write(p []byte) (int, error) {
	n, err := c.w.Write(p)
	c.h.Write(p[:n])
	c.n += int64(n)

	return n, err
}

func (c *countingHash) checksum() string {
	return "sha256:" + hex.EncodeToString(c.h.Sum(nil))
}

// checksumFile fingerprints the sealed artifact at path without reading it into memory.
func checksumFile(path string) (string, int64, error) {
	f, err := os.Open(path)
	if err != nil {
		return "", 0, err
	}

	defer func() { _ = f.Close() }()

	h := sha256.New()

	n, err := io.Copy(h, f)
	if err != nil {
		return "", 0, err
	}

	return "sha256:" + hex.EncodeToString(h.Sum(nil)), n, nil
}

// dumpFile is a database dump waiting in a temporary file, to be copied into the archive.
type dumpFile struct {
	capability string
	path       string
}

// writeNodeArtifact streams the whole-node archive into artifactPath, sealed under hexKey. The file
// only appears under its final name once it is complete: it is written beside it and renamed.
func writeNodeArtifact(ctx context.Context, cfg Config, artifactPath, hexKey string, included []string, dumps []dumpFile) (string, int64, error) {
	if err := os.MkdirAll(filepath.Dir(artifactPath), 0o750); err != nil {
		return "", 0, fmt.Errorf("artifacts root unavailable: %w", err)
	}

	part := artifactPath + ".part"

	f, err := os.OpenFile(part, os.O_WRONLY|os.O_CREATE|os.O_TRUNC, 0o600)
	if err != nil {
		return "", 0, fmt.Errorf("artifact write failed: %w", err)
	}

	checksum, size, err := sealNodeArchive(ctx, cfg, f, hexKey, included, dumps)
	if err == nil {
		err = f.Sync()
	}

	if closeErr := f.Close(); err == nil {
		err = closeErr
	}

	if err != nil {
		_ = os.Remove(part)

		return "", 0, err
	}

	if err := os.Rename(part, artifactPath); err != nil {
		_ = os.Remove(part)

		return "", 0, fmt.Errorf("artifact write failed: %w", err)
	}

	return checksum, size, nil
}

func sealNodeArchive(ctx context.Context, cfg Config, w io.Writer, hexKey string, included []string, dumps []dumpFile) (string, int64, error) {
	out := newCountingHash(w)

	sw, err := newSealWriter(out, hexKey)
	if err != nil {
		return "", 0, fmt.Errorf("encryption failed: %w", err)
	}

	gz := gzip.NewWriter(sw)
	tw := tar.NewWriter(gz)

	if err := archiveStateRootsTo(ctx, tw, cfg.SudoBinary, cfg.AgentBinaryPath, cfg.StateRoots, included, dumps); err != nil {
		return "", 0, fmt.Errorf("archive failed: %w", err)
	}

	if err := tw.Close(); err != nil {
		return "", 0, fmt.Errorf("closing tar writer: %w", err)
	}

	if err := gz.Close(); err != nil {
		return "", 0, fmt.Errorf("closing gzip writer: %w", err)
	}

	if err := sw.Close(); err != nil {
		return "", 0, fmt.Errorf("encryption failed: %w", err)
	}

	return out.checksum(), out.n, nil
}

// addFileToTar copies the file at path into the archive under name.
func addFileToTar(tw *tar.Writer, path, name string) error {
	f, err := os.Open(path)
	if err != nil {
		return err
	}

	defer func() { _ = f.Close() }()

	info, err := f.Stat()
	if err != nil {
		return err
	}

	hdr := &tar.Header{Name: name, Mode: 0o640, Size: info.Size(), ModTime: info.ModTime()}
	if err := tw.WriteHeader(hdr); err != nil {
		return err
	}

	_, err = io.Copy(tw, f)

	return err
}

// readableNodeArtifact opens the artifact at path as a stream of its plaintext archive (gzip-compressed
// tar), whichever format it was written in. The returned close function releases the file.
func readableNodeArtifact(path, hexKey string, legacyLimit int64) (io.Reader, func(), error) {
	f, err := os.Open(path)
	if err != nil {
		return nil, nil, err
	}

	closeFile := func() { _ = f.Close() }

	magic := make([]byte, len(streamMagic))
	if _, err := io.ReadFull(f, magic); err == nil && string(magic) == streamMagic {
		if _, err := f.Seek(0, io.SeekStart); err != nil {
			closeFile()

			return nil, nil, err
		}

		sr, err := newSealReader(f, hexKey)
		if err != nil {
			closeFile()

			return nil, nil, err
		}

		return sr, closeFile, nil
	}

	closeFile()

	// An artifact from before streaming: one sealed message, read whole (and so size-capped).
	if err := checkArtifactSize(path, legacyLimit); err != nil {
		return nil, nil, err
	}

	sealed, err := os.ReadFile(path)
	if err != nil {
		return nil, nil, err
	}

	plaintext, err := decryptLegacy(hexKey, sealed)
	if err != nil {
		return nil, nil, err
	}

	return bytes.NewReader(plaintext), func() {}, nil
}
