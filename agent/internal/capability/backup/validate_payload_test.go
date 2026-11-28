package backup

import (
	"encoding/json"
	"errors"
	"strings"
	"testing"

	"github.com/mikho/LESta/agent/internal/protocol"
)

const (
	validateResourceID = "00000000-0000-4000-8000-000000000001"
	validateKey        = "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"
	validateToken      = "fedcba9876543210fedcba9876543210fedcba9876543210fedcba9876543210"
)

// validateBody builds a payload from a base shape and changes, a nil value removing a field.
func validateBody(base map[string]any, changes map[string]any) json.RawMessage {
	body := map[string]any{}
	for k, v := range base {
		body[k] = v
	}

	for k, v := range changes {
		if v == nil {
			delete(body, k)
		} else {
			body[k] = v
		}
	}

	raw, _ := json.Marshal(body)

	return raw
}

func validateAccount(changes map[string]any) map[string]any {
	account := map[string]any{"username": "lesta-t42", "web_resources": []string{validateResourceID}, "mail_domains": []string{"example.com"}, "databases": []string{"t42_wp"}, "parts": []string{"files", "databases", "mail"}}
	for k, v := range changes {
		account[k] = v
	}

	return account
}

var validateDestination = map[string]any{"endpoint": "https://s3.example.com", "region": "eu-west-1", "bucket": "my-backups", "access_key": "AKIAEXAMPLE", "secret_key": "abcd1234/efgh+5678=="}

var (
	nodeCreate     = map[string]any{"label": "nightly", "encryption_key": validateKey}
	accountCreate  = map[string]any{"encryption_key": validateKey, "account": validateAccount(nil)}
	urlImport      = map[string]any{"encryption_key": validateKey, "account": validateAccount(nil), "source_url": "https://panel.example.com/agent/v1/account-backup-imports", "source_token": validateToken}
	storageImport  = map[string]any{"encryption_key": validateKey, "account": validateAccount(nil), "destination": validateDestination, "object_key": "backups/alice.tar.gz"}
	artifactOnly   = map[string]any{"artifact_path": "/var/lib/lesta/backups/x.tar.enc"}
	nodeDownload   = map[string]any{"artifact_path": "/var/lib/lesta/backups/x.tar.enc", "encryption_key": validateKey, "upload_url": "https://panel.example.com/agent/v1/backup-uploads", "upload_token": validateToken}
	accountObserve = map[string]any{"artifact_path": "/var/lib/lesta/backups/accounts/lesta-t42/x.acct.enc", "encryption_key": validateKey, "account": validateAccount(nil), "upload_url": "https://panel.example.com/agent/v1/account-backup-uploads", "upload_token": validateToken}
	nodeRestore    = map[string]any{"artifact_path": "/var/lib/lesta/backups/x.tar.enc", "encryption_key": validateKey}
	accountRestore = map[string]any{"artifact_path": "/var/lib/lesta/backups/accounts/lesta-t42/x.acct.enc", "encryption_key": validateKey, "account": validateAccount(nil)}
	accountCopy    = map[string]any{"artifact_path": "/var/lib/lesta/backups/accounts/lesta-t42/x.acct.enc", "encryption_key": validateKey, "account": validateAccount(nil), "destination": validateDestination, "object_key": "backups/alice.tar.gz"}
)

func TestValidatePayloadAcceptsEveryShape(t *testing.T) {
	for name, tc := range map[string]struct {
		operation protocol.Operation
		base      map[string]any
	}{
		"node create":       {protocol.OperationCreate, nodeCreate},
		"account create":    {protocol.OperationCreate, accountCreate},
		"import by address": {protocol.OperationCreate, urlImport},
		"import by storage": {protocol.OperationCreate, storageImport},
		"delete":            {protocol.OperationDelete, artifactOnly},
		"sealed observe":    {protocol.OperationObserve, artifactOnly},
		"node download":     {protocol.OperationObserve, nodeDownload},
		"account download":  {protocol.OperationObserve, accountObserve},
		"node restore":      {protocol.OperationRestore, nodeRestore},
		"account restore":   {protocol.OperationRestore, accountRestore},
		"account copy":      {protocol.OperationUpdate, accountCopy},
	} {
		if err := ValidatePayload(tc.operation, validateResourceID, validateBody(tc.base, nil)); err != nil {
			t.Errorf("%s: %v", name, err)
		}
	}
}

func TestValidatePayloadRefusesHostilePayloadsWithApplysCode(t *testing.T) {
	long := strings.Repeat("a", 4096)

	for name, tc := range map[string]struct {
		operation protocol.Operation
		base      map[string]any
		changes   map[string]any
		code      string
	}{
		"unsupported":             {"suspend", nodeCreate, nil, "unsupported_operation"},
		"create without key":      {protocol.OperationCreate, nodeCreate, map[string]any{"encryption_key": nil}, "invalid_encryption_key"},
		"create key newline":      {protocol.OperationCreate, nodeCreate, map[string]any{"encryption_key": validateKey + "\n"}, "invalid_encryption_key"},
		"username dot dot":        {protocol.OperationCreate, accountCreate, map[string]any{"account": validateAccount(map[string]any{"username": "../root"})}, "invalid_account"},
		"username nul":            {protocol.OperationCreate, accountCreate, map[string]any{"account": validateAccount(map[string]any{"username": "lesta-t42\x00"})}, "invalid_account"},
		"database newline":        {protocol.OperationCreate, accountCreate, map[string]any{"account": validateAccount(map[string]any{"databases": []string{"a\nb"}})}, "invalid_account"},
		"domain newline":          {protocol.OperationCreate, accountCreate, map[string]any{"account": validateAccount(map[string]any{"mail_domains": []string{"example.com\nx.com"}})}, "invalid_account"},
		"no parts":                {protocol.OperationCreate, accountCreate, map[string]any{"account": validateAccount(map[string]any{"parts": []string{}})}, "invalid_account"},
		"import two sources":      {protocol.OperationCreate, urlImport, map[string]any{"object_key": "backups/a.tar.gz"}, "invalid_import_source"},
		"import foreign address":  {protocol.OperationCreate, urlImport, map[string]any{"source_url": "https://panel.example.com/../etc/passwd"}, "import_fetch_failed"},
		"import plain http":       {protocol.OperationCreate, urlImport, map[string]any{"source_url": "http://panel.example.com/agent/v1/account-backup-imports"}, "import_fetch_failed"},
		"import token":            {protocol.OperationCreate, urlImport, map[string]any{"source_token": long}, "import_fetch_failed"},
		"import object dot dot":   {protocol.OperationCreate, storageImport, map[string]any{"object_key": "../alice.tar.gz"}, "invalid_destination"},
		"import no destination":   {protocol.OperationCreate, storageImport, map[string]any{"destination": nil}, "invalid_destination"},
		"delete no path":          {protocol.OperationDelete, artifactOnly, map[string]any{"artifact_path": ""}, "invalid_artifact_path"},
		"observe no path":         {protocol.OperationObserve, nodeDownload, map[string]any{"artifact_path": nil}, "invalid_artifact_path"},
		"node download key":       {protocol.OperationObserve, nodeDownload, map[string]any{"encryption_key": nil}, "invalid_encryption_key"},
		"node download address":   {protocol.OperationObserve, nodeDownload, map[string]any{"upload_url": "https://evil.example.com/steal?x=1"}, "invalid_upload_url"},
		"node download token":     {protocol.OperationObserve, nodeDownload, map[string]any{"upload_token": "x\n"}, "invalid_upload_url"},
		"account download no url": {protocol.OperationObserve, accountObserve, map[string]any{"upload_url": nil}, "invalid_upload_url"},
		"account download path":   {protocol.OperationObserve, accountObserve, map[string]any{"upload_url": "https://panel.example.com/agent/v1/../uploads"}, "invalid_upload_url"},
		"account download user":   {protocol.OperationObserve, accountObserve, map[string]any{"account": validateAccount(map[string]any{"username": long})}, "invalid_account"},
		"restore no key":          {protocol.OperationRestore, nodeRestore, map[string]any{"encryption_key": nil}, "invalid_encryption_key"},
		"restore no path":         {protocol.OperationRestore, nodeRestore, map[string]any{"artifact_path": nil}, "invalid_artifact_path"},
		"update not a copy":       {protocol.OperationUpdate, accountCopy, map[string]any{"destination": nil}, "unsupported_operation"},
		"update node backup":      {protocol.OperationUpdate, accountCopy, map[string]any{"account": nil}, "unsupported_operation"},
		"copy key":                {protocol.OperationUpdate, accountCopy, map[string]any{"encryption_key": "nothex"}, "invalid_encryption_key"},
		"copy endpoint":           {protocol.OperationUpdate, accountCopy, map[string]any{"destination": map[string]any{"endpoint": "http://s3.example.com", "region": "eu-west-1", "bucket": "b-b", "access_key": "AKIAEXAMPLE", "secret_key": "abcd1234/efgh"}}, "invalid_destination"},
		"copy object dot dot":     {protocol.OperationUpdate, accountCopy, map[string]any{"object_key": "a/../b"}, "invalid_destination"},
	} {
		err := ValidatePayload(tc.operation, validateResourceID, validateBody(tc.base, tc.changes))

		var verr *ValidationError
		if !errors.As(err, &verr) || verr.Code != tc.code {
			t.Errorf("%s: got %v, want code %s", name, err, tc.code)
		}
	}
}

func TestValidatePayloadRefusesUndecodablePayloads(t *testing.T) {
	for _, operation := range []protocol.Operation{protocol.OperationCreate, protocol.OperationDelete, protocol.OperationObserve, protocol.OperationRestore, protocol.OperationUpdate} {
		for _, raw := range []string{`{"label":"x","unknown":1}`, `nope`, `{"artifact_path":1}`} {
			if err := ValidatePayload(operation, validateResourceID, json.RawMessage(raw)); err == nil {
				t.Errorf("%s %s: accepted", operation, raw)
			}
		}
	}
}
