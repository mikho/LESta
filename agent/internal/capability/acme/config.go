package acme

// Config parameterizes AcmeCapability by the single root path it owns, so the
// identical implementation runs against production's real /var/lib/lesta/acme
// or a fully disposable per-test temp directory.
type Config struct {
	// StateRoot is the root this capability owns (production:
	// /var/lib/lesta/acme). Two fixed subpaths live under it, never
	// generation-numbered (unlike nginx/apache/bind9's own owned roots,
	// where the live path is a symlink into a generation directory): the
	// files this capability writes ARE the live, served state, directly.
	//
	//   - StateRoot/http-01/<token>: HTTP-01 challenge key-authorization
	//     files, the same directory nginx's own shared
	//     `.well-known/acme-challenge/` location block serves from (see
	//     internal/capability/nginx's own Config.AcmeChallengeDir).
	//   - StateRoot/certs/<domain>/{fullchain,privkey}.pem: issued
	//     certificate bundles, the same paths
	//     WebDomain::toProvisioningPayload('web.nginx.v1') points nginx's
	//     own ssl.certificate_path/private_key_path at once a certificate
	//     exists.
	//
	// Generation history (this capability's own idempotency/observe
	// bookkeeping -- monotonic per-resource-id operation log, unrelated to
	// either fixed path above) nests under StateRoot/domains, mirroring
	// nginx's and apache's own StateRoot/domains convention.
	StateRoot string
}
