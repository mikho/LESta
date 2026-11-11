package backup

import (
	"context"
	"io"
)

// LoadDatabaseForTest exposes loadDatabase to the external test package, which owns the
// disposable MariaDB harness.
func LoadDatabaseForTest(ctx context.Context, cfg Config, socket, name string, sql io.Reader) error {
	return loadDatabase(ctx, cfg, socket, name, sql)
}

// EncryptLegacyForTest seals plaintext the way artifacts were sealed before streaming.
func EncryptLegacyForTest(hexKey string, plaintext []byte) ([]byte, error) {
	return encrypt(hexKey, plaintext)
}
