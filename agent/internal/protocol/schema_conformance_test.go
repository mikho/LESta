package protocol

import (
	"encoding/json"
	"os"
	"path/filepath"
	"reflect"
	"runtime"
	"sort"
	"strings"
	"testing"
)

// schemaPropertyKeys loads a JSON Schema file and returns the set of keys declared
// under its top-level "properties" object.
func schemaPropertyKeys(t *testing.T, relPath string) map[string]struct{} {
	t.Helper()

	repoRoot := repoRootFromThisFile(t)
	path := filepath.Join(repoRoot, "docs", "protocol", relPath)

	raw, err := os.ReadFile(path)
	if err != nil {
		t.Fatalf("reading schema file %s: %v", path, err)
	}

	var schema struct {
		Properties map[string]json.RawMessage `json:"properties"`
	}
	if err := json.Unmarshal(raw, &schema); err != nil {
		t.Fatalf("parsing schema file %s: %v", path, err)
	}

	keys := make(map[string]struct{}, len(schema.Properties))
	for k := range schema.Properties {
		keys[k] = struct{}{}
	}

	return keys
}

// repoRootFromThisFile walks up from this test file's own location to find the
// repository root, so the test works regardless of the working directory `go
// test` is invoked from.
func repoRootFromThisFile(t *testing.T) string {
	t.Helper()

	_, thisFile, _, ok := runtime.Caller(0)
	if !ok {
		t.Fatal("could not determine caller for this test file")
	}

	// this file lives at <repoRoot>/agent/internal/protocol/schema_conformance_test.go
	dir := filepath.Dir(thisFile)
	repoRoot := filepath.Join(dir, "..", "..", "..")

	abs, err := filepath.Abs(repoRoot)
	if err != nil {
		t.Fatalf("resolving repo root: %v", err)
	}

	return abs
}

// structJSONTags returns the set of json tag names declared on a struct type's
// exported fields (the tag name only, ignoring options like ",omitempty").
func structJSONTags(v any) map[string]struct{} {
	t := reflect.TypeOf(v)
	tags := make(map[string]struct{}, t.NumField())

	for i := 0; i < t.NumField(); i++ {
		tag := t.Field(i).Tag.Get("json")
		if tag == "" || tag == "-" {
			continue
		}

		name, _, _ := strings.Cut(tag, ",")
		tags[name] = struct{}{}
	}

	return tags
}

func assertSameKeySet(t *testing.T, structTags, schemaKeys map[string]struct{}) {
	t.Helper()

	var missingFromStruct, missingFromSchema []string

	for k := range schemaKeys {
		if _, ok := structTags[k]; !ok {
			missingFromStruct = append(missingFromStruct, k)
		}
	}
	for k := range structTags {
		if _, ok := schemaKeys[k]; !ok {
			missingFromSchema = append(missingFromSchema, k)
		}
	}

	sort.Strings(missingFromStruct)
	sort.Strings(missingFromSchema)

	if len(missingFromStruct) > 0 {
		t.Errorf("schema declares properties the Go struct has no json tag for: %v", missingFromStruct)
	}
	if len(missingFromSchema) > 0 {
		t.Errorf("Go struct has json tags the schema does not declare as properties: %v", missingFromSchema)
	}
}

func TestSchemaConformance_OperationEnvelope(t *testing.T) {
	assertSameKeySet(t, structJSONTags(OperationEnvelope{}), schemaPropertyKeys(t, "operation-envelope.schema.json"))
}

func TestSchemaConformance_ResultEnvelope(t *testing.T) {
	assertSameKeySet(t, structJSONTags(ResultEnvelope{}), schemaPropertyKeys(t, "result-envelope.schema.json"))
}

// TestSchemaConformance_ResultError goes one level deeper than the plan's two
// top-level envelope checks: result-envelope.schema.json's own "errors.items"
// sub-schema declares ResultError's shape, so it is worth the same tripwire.
func TestSchemaConformance_ResultError(t *testing.T) {
	repoRoot := repoRootFromThisFile(t)
	path := filepath.Join(repoRoot, "docs", "protocol", "result-envelope.schema.json")

	raw, err := os.ReadFile(path)
	if err != nil {
		t.Fatalf("reading schema file %s: %v", path, err)
	}

	var schema struct {
		Properties struct {
			Errors struct {
				Items struct {
					Properties map[string]json.RawMessage `json:"properties"`
				} `json:"items"`
			} `json:"errors"`
		} `json:"properties"`
	}
	if err := json.Unmarshal(raw, &schema); err != nil {
		t.Fatalf("parsing schema file %s: %v", path, err)
	}

	schemaKeys := make(map[string]struct{}, len(schema.Properties.Errors.Items.Properties))
	for k := range schema.Properties.Errors.Items.Properties {
		schemaKeys[k] = struct{}{}
	}

	assertSameKeySet(t, structJSONTags(ResultError{}), schemaKeys)
}
