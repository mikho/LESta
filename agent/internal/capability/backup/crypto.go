package backup

import (
	"crypto/aes"
	"crypto/cipher"
	"crypto/rand"
	"encoding/hex"
	"fmt"
)

// encryptionKeySize is 32 bytes: AES-256's own required key length. Laravel
// generates the plaintext key as bin2hex(random_bytes(32)) (see
// app/Actions/Backups/CreateBackup.php), a 64-character hex string that
// decodes to exactly this many bytes -- already high-entropy random
// material, so this capability uses it directly as the AES-256 key with no
// KDF in between.
const encryptionKeySize = 32

// nonceSize is AES-GCM's own standard 12-byte nonce length.
const nonceSize = 12

// encrypt seals plaintext under AES-256-GCM with a fresh random nonce,
// returning nonce||ciphertext||tag concatenated as the artifact's own
// on-disk format: a restore need only split off the first nonceSize bytes.
func encrypt(hexKey string, plaintext []byte) ([]byte, error) {
	gcm, err := newGCM(hexKey)
	if err != nil {
		return nil, err
	}

	nonce := make([]byte, nonceSize)
	if _, err := rand.Read(nonce); err != nil {
		return nil, fmt.Errorf("generating nonce: %w", err)
	}

	return gcm.Seal(nonce, nonce, plaintext, nil), nil
}

// Decrypt reverses encrypt: given the same plaintext hex key a create
// request was issued with, it recovers the original archive bytes from a
// sealed artifact's own on-disk contents. No production code path in this
// agent calls it yet (restore is out of scope for this v1 pass, and Laravel
// never retains the plaintext key past the create request that generated
// it), but it is exported rather than kept test-only: it is the one real
// primitive a future restore command needs, and this package's own tests
// use it to prove a genuine round trip against a real encrypted artifact
// rather than just asserting bytes changed.
func Decrypt(hexKey string, sealed []byte) ([]byte, error) {
	if len(sealed) < nonceSize {
		return nil, fmt.Errorf("sealed artifact too short: %d bytes", len(sealed))
	}

	gcm, err := newGCM(hexKey)
	if err != nil {
		return nil, err
	}

	nonce, ciphertext := sealed[:nonceSize], sealed[nonceSize:]

	return gcm.Open(nil, nonce, ciphertext, nil)
}

func newGCM(hexKey string) (cipher.AEAD, error) {
	key, err := hex.DecodeString(hexKey)
	if err != nil {
		return nil, fmt.Errorf("decoding encryption key: %w", err)
	}

	if len(key) != encryptionKeySize {
		return nil, fmt.Errorf("encryption key must decode to %d bytes, got %d", encryptionKeySize, len(key))
	}

	block, err := aes.NewCipher(key)
	if err != nil {
		return nil, fmt.Errorf("constructing AES cipher: %w", err)
	}

	return cipher.NewGCM(block)
}
