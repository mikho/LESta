package metrics

import (
	"bytes"
	"encoding/json"
	"fmt"
	"regexp"
)

// domainPattern/localPartPattern mirror mail's own exactly (see
// agent/internal/capability/mail/payload.go): a MailAccountRef names a real
// mailbox this capability's own disk-usage walk must resolve to a real,
// already-existing maildir path, never an arbitrary string.
var domainPattern = regexp.MustCompile(`^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$`)
var localPartPattern = regexp.MustCompile(`^[a-z0-9](?:[a-z0-9._+-]*[a-z0-9])?$`)

// databaseNamePattern/statsUserPattern/statsPasswordPattern mirror mariadb's
// own exactly (see agent/internal/capability/mariadb/payload.go): the same
// fixed, quote-and-backslash-free charsets that let every SQL string below
// interpolate these values directly, with no escaping logic of its own.
var databaseNamePattern = regexp.MustCompile(`^lesta_[0-9]+_[a-z][a-z0-9_]{0,32}$`)
var statsUserPattern = regexp.MustCompile(`^lesta_[0-9]+_[a-z][a-z0-9_]{0,32}_ro$`)
var statsPasswordPattern = regexp.MustCompile(`^[0-9a-f]{48}$`)

// resourceUUIDPattern is a bare v4-shaped UUID: every *Ref's own
// ResourceUUID is never interpreted, only echoed back verbatim in the
// response and used to build this capability's own on-disk offset-
// bookkeeping filename (see weblog.go), so it is validated defensively
// against path-traversal/injection the same way every other capability in
// this module validates a resource_id before using it in a filesystem path.
var resourceUUIDPattern = regexp.MustCompile(`^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$`)

// MailAccountRef names one real mailbox this observe call should measure
// real maildir disk usage for.
type MailAccountRef struct {
	ResourceUUID string `json:"resource_uuid"`
	Domain       string `json:"domain"`
	LocalPart    string `json:"local_part"`
}

// TenantDatabaseRef names one real tenant database this observe call should
// measure real information_schema disk usage for, connecting with its own
// dedicated read-only stats credentials (never the tenant's own write
// credentials, never a shared admin account).
type TenantDatabaseRef struct {
	ResourceUUID  string `json:"resource_uuid"`
	DatabaseName  string `json:"database_name"`
	StatsUser     string `json:"stats_user"`
	StatsPassword string `json:"stats_password"`
}

// WebResourceRef names one real vhost this observe call should measure real
// bandwidth/request-count usage for, since its own last collection.
type WebResourceRef struct {
	ResourceUUID string `json:"resource_uuid"`
	WebServer    string `json:"web_server"`
}

// Payload is the metrics.usage.v1 capability's request body: a manifest of
// every resource to measure, assembled by Laravel from its own database
// (this capability has no independent way to discover which accounts/
// domains/databases exist on this node -- see this package's own doc
// comment for why that is the correct division of responsibility here,
// unlike mail/bind9's own self-discovering StateRoot enumeration).
type Payload struct {
	MailAccounts    []MailAccountRef    `json:"mail_accounts"`
	TenantDatabases []TenantDatabaseRef `json:"tenant_databases"`
	WebResources    []WebResourceRef    `json:"web_resources"`
}

// ValidationError is a well-formed payload rejection: a schema-shaped (code,
// message, field) triple the caller turns directly into a rejected
// ResultEnvelope. It is never a Go error representing "no verdict was
// reached".
type ValidationError struct {
	Code    string
	Message string
	Field   string
}

func (e *ValidationError) Error() string {
	return fmt.Sprintf("%s: %s (field=%s)", e.Code, e.Message, e.Field)
}

// ParsePayload decodes and validates raw as a Payload. Unknown fields are a
// hard decode error, matching the envelope decode discipline of every other
// capability in this module.
func ParsePayload(raw json.RawMessage) (Payload, error) {
	var p Payload

	dec := json.NewDecoder(bytes.NewReader(raw))
	dec.DisallowUnknownFields()
	if err := dec.Decode(&p); err != nil {
		return Payload{}, fmt.Errorf("decoding metrics payload: %w", err)
	}

	for i, m := range p.MailAccounts {
		if !resourceUUIDPattern.MatchString(m.ResourceUUID) {
			return Payload{}, &ValidationError{Code: "invalid_resource_uuid", Message: fmt.Sprintf("mail_accounts[%d].resource_uuid is not a valid UUID", i), Field: "mail_accounts"}
		}
		if !domainPattern.MatchString(m.Domain) {
			return Payload{}, &ValidationError{Code: "invalid_domain", Message: fmt.Sprintf("mail_accounts[%d].domain does not match the expected normalized shape", i), Field: "mail_accounts"}
		}
		if !localPartPattern.MatchString(m.LocalPart) {
			return Payload{}, &ValidationError{Code: "invalid_local_part", Message: fmt.Sprintf("mail_accounts[%d].local_part does not match the expected shape", i), Field: "mail_accounts"}
		}
	}

	for i, d := range p.TenantDatabases {
		if !resourceUUIDPattern.MatchString(d.ResourceUUID) {
			return Payload{}, &ValidationError{Code: "invalid_resource_uuid", Message: fmt.Sprintf("tenant_databases[%d].resource_uuid is not a valid UUID", i), Field: "tenant_databases"}
		}
		if !databaseNamePattern.MatchString(d.DatabaseName) {
			return Payload{}, &ValidationError{Code: "invalid_database_name", Message: fmt.Sprintf("tenant_databases[%d].database_name does not match the expected shape", i), Field: "tenant_databases"}
		}
		if !statsUserPattern.MatchString(d.StatsUser) {
			return Payload{}, &ValidationError{Code: "invalid_stats_user", Message: fmt.Sprintf("tenant_databases[%d].stats_user does not match the expected shape", i), Field: "tenant_databases"}
		}
		if !statsPasswordPattern.MatchString(d.StatsPassword) {
			return Payload{}, &ValidationError{Code: "invalid_stats_password", Message: fmt.Sprintf("tenant_databases[%d].stats_password must be exactly 48 lowercase hex characters", i), Field: "tenant_databases"}
		}
	}

	for i, w := range p.WebResources {
		if !resourceUUIDPattern.MatchString(w.ResourceUUID) {
			return Payload{}, &ValidationError{Code: "invalid_resource_uuid", Message: fmt.Sprintf("web_resources[%d].resource_uuid is not a valid UUID", i), Field: "web_resources"}
		}
		if w.WebServer != "nginx" && w.WebServer != "apache" {
			return Payload{}, &ValidationError{Code: "invalid_web_server", Message: fmt.Sprintf("web_resources[%d].web_server must be \"nginx\" or \"apache\"", i), Field: "web_resources"}
		}
	}

	return p, nil
}
