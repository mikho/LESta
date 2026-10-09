package backup

import (
	"crypto/cipher"
	"crypto/rand"
	"encoding/binary"
	"errors"
	"fmt"
	"io"
)

// This file is the streaming counterpart of crypto.go. A per-account backup
// can be gigabytes, so it is sealed in chunks instead of held in memory:
// "LESTABK1", an 8-byte random nonce prefix, then chunks of up to 64 KB, each
// a 4-byte big-endian ciphertext length followed by AES-256-GCM output. The
// 12-byte nonce of a chunk is the prefix followed by its 4-byte counter, and
// the associated data is one byte, 1 for the last chunk and 0 for every other,
// so a stream that was cut short (or had chunks reordered, dropped or
// repeated) fails authentication instead of restoring a partial archive.

const (
	streamMagic     = "LESTABK1"
	streamChunkSize = 64 << 10
	streamPrefixLen = 8
	// maxChunkCipherLen bounds a chunk length read from disk before allocating.
	maxChunkCipherLen = streamChunkSize + 64
)

var errStreamCounterExhausted = errors.New("backup stream is too long to seal")

// sealWriter encrypts everything written to it into w. Close must be called to
// write the final chunk.
type sealWriter struct {
	w       io.Writer
	gcm     cipher.AEAD
	prefix  [streamPrefixLen]byte
	counter uint32
	buf     []byte
	closed  bool
}

func newSealWriter(w io.Writer, hexKey string) (*sealWriter, error) {
	gcm, err := newGCM(hexKey)
	if err != nil {
		return nil, err
	}

	s := &sealWriter{w: w, gcm: gcm, buf: make([]byte, 0, streamChunkSize)}

	if _, err := rand.Read(s.prefix[:]); err != nil {
		return nil, fmt.Errorf("generating nonce prefix: %w", err)
	}

	if _, err := io.WriteString(w, streamMagic); err != nil {
		return nil, fmt.Errorf("writing stream header: %w", err)
	}

	if _, err := w.Write(s.prefix[:]); err != nil {
		return nil, fmt.Errorf("writing stream header: %w", err)
	}

	return s, nil
}

func (s *sealWriter) Write(p []byte) (int, error) {
	if s.closed {
		return 0, errors.New("write to a closed backup stream")
	}

	written := 0

	for len(p) > 0 {
		// A full buffer is only flushed when more data arrives, so the last
		// chunk is always the one Close seals as final.
		if len(s.buf) == streamChunkSize {
			if err := s.flush(false); err != nil {
				return written, err
			}
		}

		n := min(streamChunkSize-len(s.buf), len(p))
		s.buf = append(s.buf, p[:n]...)
		p = p[n:]
		written += n
	}

	return written, nil
}

// Close seals the remaining data as the final chunk.
func (s *sealWriter) Close() error {
	if s.closed {
		return nil
	}

	s.closed = true

	return s.flush(true)
}

func (s *sealWriter) flush(final bool) error {
	if s.counter == ^uint32(0) {
		return errStreamCounterExhausted
	}

	nonce := chunkNonce(s.prefix, s.counter)
	sealed := s.gcm.Seal(nil, nonce, s.buf, []byte{finalFlag(final)})

	var length [4]byte
	binary.BigEndian.PutUint32(length[:], uint32(len(sealed)))

	if _, err := s.w.Write(length[:]); err != nil {
		return fmt.Errorf("writing a backup chunk: %w", err)
	}

	if _, err := s.w.Write(sealed); err != nil {
		return fmt.Errorf("writing a backup chunk: %w", err)
	}

	s.counter++
	s.buf = s.buf[:0]

	return nil
}

func chunkNonce(prefix [streamPrefixLen]byte, counter uint32) []byte {
	nonce := make([]byte, nonceSize)
	copy(nonce, prefix[:])
	binary.BigEndian.PutUint32(nonce[streamPrefixLen:], counter)

	return nonce
}

func finalFlag(final bool) byte {
	if final {
		return 1
	}

	return 0
}

// sealReader decrypts a stream produced by sealWriter. Reading to io.EOF means
// the whole stream, including its final chunk, authenticated.
type sealReader struct {
	r       io.Reader
	gcm     cipher.AEAD
	prefix  [streamPrefixLen]byte
	counter uint32

	pending []byte
	next    []byte
	hasNext bool
	plain   []byte
	done    bool
}

func newSealReader(r io.Reader, hexKey string) (*sealReader, error) {
	gcm, err := newGCM(hexKey)
	if err != nil {
		return nil, err
	}

	header := make([]byte, len(streamMagic)+streamPrefixLen)
	if _, err := io.ReadFull(r, header); err != nil {
		return nil, fmt.Errorf("reading the backup header: %w", err)
	}

	if string(header[:len(streamMagic)]) != streamMagic {
		return nil, errors.New("this is not a LESta account backup")
	}

	s := &sealReader{r: r, gcm: gcm}
	copy(s.prefix[:], header[len(streamMagic):])

	// Read one chunk ahead: whether a chunk is the last is known only when no
	// further chunk follows, and that is what its associated data must say.
	if err := s.advance(); err != nil {
		return nil, err
	}

	return s, nil
}

// readChunk reads the next sealed chunk, or nil at a clean end of the stream.
func (s *sealReader) readChunk() ([]byte, error) {
	var length [4]byte

	if _, err := io.ReadFull(s.r, length[:]); err != nil {
		if errors.Is(err, io.EOF) {
			return nil, nil
		}

		return nil, fmt.Errorf("reading a backup chunk: %w", err)
	}

	n := binary.BigEndian.Uint32(length[:])
	if n < uint32(s.gcm.Overhead()) || n > maxChunkCipherLen {
		return nil, errors.New("the backup is damaged (bad chunk length)")
	}

	chunk := make([]byte, n)
	if _, err := io.ReadFull(s.r, chunk); err != nil {
		return nil, fmt.Errorf("the backup is truncated: %w", err)
	}

	return chunk, nil
}

func (s *sealReader) advance() error {
	if !s.hasNext {
		first, err := s.readChunk()
		if err != nil {
			return err
		}

		if first == nil {
			return errors.New("the backup is truncated (no data)")
		}

		s.pending = first
	} else {
		s.pending = s.next
	}

	next, err := s.readChunk()
	if err != nil {
		return err
	}

	s.next, s.hasNext = next, next != nil

	return nil
}

func (s *sealReader) Read(p []byte) (int, error) {
	for len(s.plain) == 0 {
		if s.done {
			return 0, io.EOF
		}

		final := !s.hasNext
		nonce := chunkNonce(s.prefix, s.counter)

		plain, err := s.gcm.Open(nil, nonce, s.pending, []byte{finalFlag(final)})
		if err != nil {
			return 0, errors.New("the backup failed authentication: it was modified or truncated, or the key is wrong")
		}

		s.counter++
		s.plain = plain

		if final {
			s.done = true
		} else if err := s.advance(); err != nil {
			return 0, err
		}
	}

	n := copy(p, s.plain)
	s.plain = s.plain[n:]

	return n, nil
}
