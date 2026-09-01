<?php

return [

    /*
    |--------------------------------------------------------------------------
    | ACME Directory URL
    |--------------------------------------------------------------------------
    |
    | Defaults to Let's Encrypt's own STAGING directory, matching the ADR's
    | "staging endpoints" gate: production issuance is opt-in only, never the
    | default. AcmeAccount has one row per directory_url, so switching this
    | value never reuses a staging-registered account key against
    | production or vice versa.
    |
    */

    'directory_url' => env('ACME_DIRECTORY_URL', 'https://acme-staging-v02.api.letsencrypt.org/directory'),

    /*
    |--------------------------------------------------------------------------
    | CA Bundle
    |--------------------------------------------------------------------------
    |
    | Optional path to an additional CA certificate AcmeClientFactory's own
    | Guzzle client should trust, on top of (never instead of) the system's
    | real CA bundle -- there is deliberately no "disable verification"
    | toggle. Null in production (Guzzle's own default: verify against the
    | real system CA bundle, which is exactly right for a real Let's
    | Encrypt directory). Tests point this at a real disposable Pebble
    | instance's own self-signed certificate.
    |
    */

    'ca_bundle' => env('ACME_CA_BUNDLE'),

    /*
    |--------------------------------------------------------------------------
    | Contact Email
    |--------------------------------------------------------------------------
    |
    | Registered with the ACME account on first lazy creation. Optional --
    | Let's Encrypt (and Pebble) both accept an account with no contact.
    |
    */

    'contact_email' => env('ACME_CONTACT_EMAIL'),

    /*
    |--------------------------------------------------------------------------
    | Protocol Timeouts
    |--------------------------------------------------------------------------
    |
    | Seconds IssueAcmeCertificate waits for the CA to validate a challenge,
    | and separately for an order to reach a terminal status after
    | finalization.
    |
    */

    'challenge_timeout' => (int) env('ACME_CHALLENGE_TIMEOUT', 90),

    'order_timeout' => (int) env('ACME_ORDER_TIMEOUT', 90),

    /*
    |--------------------------------------------------------------------------
    | Per-Domain Backoff
    |--------------------------------------------------------------------------
    |
    | Hours a domain whose most recent issuance attempt failed
    | (last_certificate_error is set) is left alone before IssueAcmeCertificate
    | will attempt it again, satisfying the ADR's "bounded retries" gate
    | without a dedicated next-attempt-at column: WebDomain's own updated_at
    | (bumped whenever last_certificate_error is written) is the timestamp
    | this backoff is measured from.
    |
    */

    'retry_after_hours' => (int) env('ACME_RETRY_AFTER_HOURS', 6),

    /*
    |--------------------------------------------------------------------------
    | Global Rate Limit
    |--------------------------------------------------------------------------
    |
    | Total issuance attempts (across every domain) IssueAcmeCertificate will
    | make per hour, via Laravel's own RateLimiter facade -- the ADR's own
    | "per-account and per-node rate limits" gate.
    |
    */

    'rate_limit_per_hour' => (int) env('ACME_RATE_LIMIT_PER_HOUR', 20),

    /*
    |--------------------------------------------------------------------------
    | Renewal Window
    |--------------------------------------------------------------------------
    |
    | Days before certificate_expires_at that the acme:renew-certificates
    | scheduled command starts re-issuing.
    |
    */

    'renew_within_days' => (int) env('ACME_RENEW_WITHIN_DAYS', 30),

];
