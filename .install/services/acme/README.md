# ACME

Certificate issuance is a capability of the web service, not an independent tenant-facing daemon. It uses bounded retries, staging endpoints, per-account and per-node rate limits, challenge cleanup, and protected account keys. Both HTTP-01 and DNS-01 challenges are supported. HTTP-01 requires only the selected web profile and is available as soon as `web.nginx.v1`/`web.apache.v1` is healthy. DNS-01 additionally requires `dns.bind9.v1` to be registered and healthy at request time; a DNS-01 request made before BIND is installed fails that specific challenge type rather than degrading the whole ACME capability.

## No standalone installer, by design

There is no `install.sh` in this directory, and there is no operator-run bootstrap step at all for `tls.acme.v1` -- unlike `firewall`/`node-health`, this isn't even embedded inside another installer. Certificate issuance (`App\Jobs\IssueAcmeCertificate`, dispatched by `App\Actions\Provisioning\TriggersAcmeCertificateIssuance`) is a Laravel-side queued job that runs automatically once a `WebDomain` exists and its web server is healthy; there is nothing on the node itself to install first. This manifest exists purely so `mail`'s own `depends_on` can name `tls.acme.v1` as a real dependency.
