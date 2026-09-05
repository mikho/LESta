# Agent daemon

A required-once-per-node operational step, not tied to any single leaf capability. It enrolls this node with the control plane and activates a long-running, systemd-supervised daemon (`lesta-agent daemon`) that heartbeats this node's own liveness and capability presence, and reports cron execution history, back to Laravel.

## Enrollment workflow

1. On the control plane, issue a one-time, 30-minute enrollment token for the node's own `uuid`:

   ```
   php artisan lesta:nodes:issue-enrollment-token <node_uuid>
   ```

   The raw token is printed once and is not recoverable; issue a new one if it is lost.

2. On the node, run this installer with the printed token:

   ```
   .install/services/agent-daemon/install.sh --apply --yes \
       --node-uuid <node_uuid> \
       --enrollment-token <token> \
       --control-plane-url https://panel.example
   ```

   The installer exchanges the token for a long-lived node credential (a 0600, root:lesta-owned file at `/etc/lesta/agent/node-credential`), writes the daemon's own config file, and enables the `lesta-agent-daemon` systemd unit.

## Not mutual TLS

This design authenticates the daemon to the control plane with a bearer token carried in the `Authorization` header, over Laravel's own already-terminated HTTPS. It is **not** literal client-certificate mutual TLS, and this installer builds no certificate authority, client certificate, or TLS termination configuration of its own.

This means the control-plane host named by `--control-plane-url` **must actually terminate HTTPS** for this to be secure: an `http://` URL carries the enrollment token and the resulting node credential in plaintext. This is a real, disclosed operational dependency, not a hidden assumption, and it is the operator's own responsibility to pass an `https://` URL in any real deployment.

## Re-running

The installer is idempotent: if `/etc/lesta/agent/node-credential` already exists and is non-empty, a re-run skips the enrollment POST entirely and treats the existing credential as already-enrolled, only re-writing the daemon config and systemd unit.
