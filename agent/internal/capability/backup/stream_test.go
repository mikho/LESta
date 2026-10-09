package backup

import (
	"bytes"
	"crypto/rand"
	"encoding/hex"
	"io"
	"testing"
)

func testKey(t *testing.T) string {
	t.Helper()

	key := make([]byte, 32)
	if _, err := rand.Read(key); err != nil {
		t.Fatalf("generating a key: %v", err)
	}

	return hex.EncodeToString(key)
}

func sealBytes(t *testing.T, key string, data []byte) []byte {
	t.Helper()

	var out bytes.Buffer

	w, err := newSealWriter(&out, key)
	if err != nil {
		t.Fatalf("newSealWriter: %v", err)
	}

	if _, err := w.Write(data); err != nil {
		t.Fatalf("Write: %v", err)
	}

	if err := w.Close(); err != nil {
		t.Fatalf("Close: %v", err)
	}

	return out.Bytes()
}

func TestSealedStreamRoundTripsAtEveryChunkBoundary(t *testing.T) {
	key := testKey(t)

	for _, size := range []int{0, 1, streamChunkSize - 1, streamChunkSize, streamChunkSize + 1, 3*streamChunkSize + 17} {
		data := make([]byte, size)
		_, _ = rand.Read(data)

		sealed := sealBytes(t, key, data)

		r, err := newSealReader(bytes.NewReader(sealed), key)
		if err != nil {
			t.Fatalf("size %d: newSealReader: %v", size, err)
		}

		got, err := io.ReadAll(r)
		if err != nil {
			t.Fatalf("size %d: reading back: %v", size, err)
		}

		if !bytes.Equal(got, data) {
			t.Errorf("size %d: the round trip changed the data", size)
		}
	}
}

func TestSealedStreamRejectsTamperingTruncationReorderAndTheWrongKey(t *testing.T) {
	key := testKey(t)
	data := make([]byte, 3*streamChunkSize+5)
	_, _ = rand.Read(data)
	sealed := sealBytes(t, key, data)

	readAll := func(b []byte, k string) error {
		r, err := newSealReader(bytes.NewReader(b), k)
		if err != nil {
			return err
		}

		_, err = io.ReadAll(r)

		return err
	}

	if err := readAll(sealed, key); err != nil {
		t.Fatalf("the untouched stream must read: %v", err)
	}

	flipped := bytes.Clone(sealed)
	flipped[len(flipped)/2] ^= 0xff

	if readAll(flipped, key) == nil {
		t.Errorf("a modified chunk must fail authentication")
	}

	// Cut at the second chunk boundary: the new last chunk was not sealed as final.
	firstChunk := len(streamMagic) + streamPrefixLen + 4 + streamChunkSize + 16
	secondChunk := firstChunk + 4 + streamChunkSize + 16

	for name, cut := range map[string][]byte{
		"cut at a chunk boundary": sealed[:secondChunk],
		"cut inside a chunk":      sealed[:secondChunk-100],
		"header only":             sealed[:len(streamMagic)+streamPrefixLen],
	} {
		if readAll(cut, key) == nil {
			t.Errorf("%s: a truncated stream must not read cleanly", name)
		}
	}

	reordered := bytes.Clone(sealed)
	a := len(streamMagic) + streamPrefixLen
	chunkLen := 4 + streamChunkSize + 16
	copy(reordered[a:a+chunkLen], sealed[a+chunkLen:a+2*chunkLen])
	copy(reordered[a+chunkLen:a+2*chunkLen], sealed[a:a+chunkLen])

	if readAll(reordered, key) == nil {
		t.Errorf("reordered chunks must fail authentication")
	}

	if readAll(sealed, testKey(t)) == nil {
		t.Errorf("the wrong key must fail")
	}

	if readAll([]byte("not a backup at all"), key) == nil {
		t.Errorf("a file that is not a backup must be refused")
	}
}
