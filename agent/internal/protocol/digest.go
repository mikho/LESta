package protocol

import (
	"bytes"
	"crypto/sha256"
	"crypto/subtle"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"sort"
	"strconv"
)

// RequestDigest is the request_digest the panel puts on an operation envelope: sha256 over a
// canonical JSON serialization of the fields that say what is asked for. app/Services/Provisioning/
// RequestDigest.php computes the same value; both are tested against
// docs/protocol/request-digest-vectors.json.
//
// Canonical form: an object with the keys capability, desired_state_version, operation, payload and
// resource_id, written with every object's keys in byte order, no whitespace, "/" unescaped, and
// every non-ASCII character as a lower-case \uXXXX escape. A number is written exactly as it is
// written on the wire. The digest guards against corruption, not against forgery: it carries no
// secret.
func RequestDigest(op OperationEnvelope) (string, error) {
	var canonical bytes.Buffer

	canonical.WriteString(`{"capability":`)
	writeCanonicalString(&canonical, op.Capability)
	fmt.Fprintf(&canonical, `,"desired_state_version":%d,"operation":`, op.DesiredStateVersion)
	writeCanonicalString(&canonical, string(op.Operation))
	canonical.WriteString(`,"payload":`)

	if len(bytes.TrimSpace(op.Payload)) == 0 {
		return "", fmt.Errorf("operation has no payload")
	}

	if err := writeCanonicalJSON(&canonical, op.Payload); err != nil {
		return "", fmt.Errorf("canonicalizing payload: %w", err)
	}

	canonical.WriteString(`,"resource_id":`)
	writeCanonicalString(&canonical, op.ResourceID)
	canonical.WriteByte('}')

	sum := sha256.Sum256(canonical.Bytes())

	return "sha256:" + hex.EncodeToString(sum[:]), nil
}

// VerifyRequestDigest reports whether op.RequestDigest is the digest of the operation as received.
func VerifyRequestDigest(op OperationEnvelope) error {
	want, err := RequestDigest(op)
	if err != nil {
		return err
	}

	if subtle.ConstantTimeCompare([]byte(want), []byte(op.RequestDigest)) != 1 {
		return fmt.Errorf("request_digest %q does not match the operation received (%s)", op.RequestDigest, want)
	}

	return nil
}

func writeCanonicalJSON(out *bytes.Buffer, raw []byte) error {
	dec := json.NewDecoder(bytes.NewReader(raw))
	dec.UseNumber()

	var value any
	if err := dec.Decode(&value); err != nil {
		return err
	}

	if _, err := dec.Token(); err != io.EOF {
		return fmt.Errorf("unexpected data after the JSON value")
	}

	return writeCanonicalValue(out, value)
}

func writeCanonicalValue(out *bytes.Buffer, value any) error {
	switch v := value.(type) {
	case nil:
		out.WriteString("null")
	case bool:
		out.WriteString(strconv.FormatBool(v))
	case json.Number:
		out.WriteString(v.String())
	case string:
		writeCanonicalString(out, v)
	case []any:
		out.WriteByte('[')

		for i, item := range v {
			if i > 0 {
				out.WriteByte(',')
			}

			if err := writeCanonicalValue(out, item); err != nil {
				return err
			}
		}

		out.WriteByte(']')
	case map[string]any:
		keys := make([]string, 0, len(v))
		for key := range v {
			keys = append(keys, key)
		}
		sort.Strings(keys)

		out.WriteByte('{')

		for i, key := range keys {
			if i > 0 {
				out.WriteByte(',')
			}

			writeCanonicalString(out, key)
			out.WriteByte(':')

			if err := writeCanonicalValue(out, v[key]); err != nil {
				return err
			}
		}

		out.WriteByte('}')
	default:
		return fmt.Errorf("unexpected JSON value of type %T", value)
	}

	return nil
}

// writeCanonicalString writes s the way PHP's json_encode does with JSON_UNESCAPED_SLASHES: the
// short escapes for quote, backslash and the five named control characters, \u00xx for the other
// control characters, and \uXXXX (surrogate pairs above U+FFFF) for everything non-ASCII.
func writeCanonicalString(out *bytes.Buffer, s string) {
	out.WriteByte('"')

	for _, r := range s {
		switch {
		case r == '"':
			out.WriteString(`\"`)
		case r == '\\':
			out.WriteString(`\\`)
		case r == '\b':
			out.WriteString(`\b`)
		case r == '\f':
			out.WriteString(`\f`)
		case r == '\n':
			out.WriteString(`\n`)
		case r == '\r':
			out.WriteString(`\r`)
		case r == '\t':
			out.WriteString(`\t`)
		case r < 0x20:
			fmt.Fprintf(out, `\u%04x`, r)
		case r < 0x80:
			out.WriteRune(r)
		case r >= 0x10000:
			r -= 0x10000
			fmt.Fprintf(out, `\u%04x\u%04x`, 0xd800+(r>>10), 0xdc00+(r&0x3ff))
		default:
			fmt.Fprintf(out, `\u%04x`, r)
		}
	}

	out.WriteByte('"')
}
