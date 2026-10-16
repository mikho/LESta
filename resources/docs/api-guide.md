# LESta API Guide

The LESta API lets a script do what you do in the panel, as you. This guide covers what exists today: API tokens, how to call the API, and reading everything an account has. Changing things through the API comes next (see Chapter 6).

---

## Chapter 1: What the API is

- **The same panel, another door.** Every API call runs the same checks the panel does. A script using your token can never do more than you can in the panel, only less.
- **Version 1** lives under `/api/v1` on your panel's address, for example `https://panel.example.com/api/v1`. A future breaking change gets a new version (`/api/v2`); version 1 stays available for an announced period.
- **Machine-readable description:** the full contract is an OpenAPI 3.1 file in the LESta repository, `docs/api/openapi.yaml`. Generate a client from it, or read it alongside this guide.
- **JSON only.** Send `Accept: application/json`. Responses put their content under `data`.

---

## Chapter 2: API tokens

Who can have tokens: **account owners** (a reseller account's owner included) and **platform administrators**. Other account members cannot create tokens.

Create one under `Settings → API tokens` (`/settings/api-tokens`). The page asks for your password again before it opens.

- **Name**: what the token is for, for example "Deploy script". Shown in the list and in the audit log.
- **Abilities**: read and/or write per area: Web domains, DNS, Mail, Databases, Cron jobs, Backups, and read for Usage. Platform administrators also get **Platform administration** (read and write). Tick only what the script needs. Write does not include read: a script that reads and changes DNS needs both.
- **Restrict to one account** (optional): the token then reaches only that account, even if you own several or manage accounts as a reseller.
- **Expires after**: 1 to 365 days, 90 by default. Every token expires. The list marks a token **Expires soon** in its last 14 days and **Expired** after that.

**The token is shown once**, right after you create it, with a copy button. Only a hash of it is stored, so nobody, administrators included, can show it to you again. If you lose it, revoke it and create a new one. Tokens start with `lesta_`, so a leaked one is easy to recognise in logs and secret scanners.

The list shows each token's abilities, its account restriction, when it expires, and when and from which address it was last used. **Revoke** removes a token at once; a script using it gets `401` from its next call. Creating and revoking tokens is recorded in the audit log. Deleting your login deletes your tokens.

What a token can never do, whatever its abilities: create or revoke tokens, change your password, two-factor settings or passkeys, sign in as someone else, or open the support view of an account.

---

## Chapter 3: Calling the API

Send the token in the `Authorization` header, never in the address:

```
curl -H "Authorization: Bearer lesta_..." \
     -H "Accept: application/json" \
     https://panel.example.com/api/v1/user
```

A token in the query string is not accepted. The API keeps no session and sets no cookies.

### Responses and errors

- `200` with the result under `data`.
- `401`: the token is missing, unknown, revoked or expired. This is checked first, before anything in the address is looked up.
- `403`: the token lacks the ability for this call, it is restricted to another account, or you are not a member of the account in the address.
- `404`: the resource does not exist, or it belongs to another account than the one in the address.
- `422`: the input is invalid; the field errors are under `errors`, in the same shape the panel uses.
- `429`: too many requests (see below), with a `Retry-After` header in seconds.

Errors carry a human-readable `message`.

### Paging

Lists are paged with a cursor. Pass `limit` (1 to 100, 50 by default). The response has `links.next` (null on the last page) and `meta.next_cursor`; send that cursor back as `?cursor=...` to get the next page. Lists are in the order the resources were created, except backups and usage, which are newest first.

### Ids

Accounts are addressed by the `id` from `GET /accounts`, a short string such as `ZQucS8keP4J7`. Everything inside an account (domains, zones, mailboxes, databases, cron jobs, backups) is addressed by its `id`, a UUID. Timestamps are ISO 8601 in UTC, for example `2026-10-16T09:30:00Z`.

### Rate limits

- Per token: 120 reads and 30 writes a minute.
- Per user, across all of their tokens: twice that.
- Failed tokens: 30 a minute from one address, after which that address is refused for a minute, valid token or not. A script with a typo in its token can lock itself out briefly.

---

## Chapter 4: Endpoints

### `GET /api/v1/user`

The token's user and the token itself. The first call a script makes, to check its token works.

```json
{
    "data": {
        "id": 12,
        "name": "Ada Owner",
        "email": "ada@example.com",
        "token": {
            "id": "6f1c...",
            "name": "Deploy script",
            "abilities": ["web:read", "dns:write"],
            "account_id": null,
            "expires_at": "2027-01-14T10:00:00Z"
        }
    }
}
```

### `GET /api/v1/accounts`

The accounts the token reaches: your own accounts, the accounts managed by a reseller account you own, and only the one account when the token is restricted to it. Paged as in Chapter 3.

Each account has `id`, `name`, `contact_email`, `package` (`id`, `name`), `is_owner` (whether you act as its owner), `reseller_account_id` (the `id` of the reseller account that manages it, or null), `suspended` and `created_at`.

### Reading an account's resources

Every path below starts with `/api/v1/accounts/{account}`, where `{account}` is the account's `id`. Lists are paged as in Chapter 3. A suspended account can still be read; each resource says whether it is suspended.

| Path                                  | Ability          | What it returns                                                                                                                       |
| ------------------------------------- | ---------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| `/web-domains`, `/web-domains/{id}`   | `web:read`       | Web domains: aliases, web server and PHP version, SSL mode and certificate dates, firewall settings, hotlink protection.              |
| `/dns-zones`, `/dns-zones/{id}`       | `dns:read`       | DNS zones with their TTL and number of records.                                                                                       |
| `/dns-zones/{id}/records`             | `dns:read`       | One zone's records: name, type, priority, value.                                                                                      |
| `/mail-domains`, `/mail-domains/{id}` | `mail:read`      | Mail domains: antivirus, antispam, DKIM, catch-all, number of mailboxes.                                                              |
| `/mail-domains/{id}/mailboxes`        | `mail:read`      | One mail domain's mailboxes: address, quota, forwarding, autoreply.                                                                   |
| `/databases`, `/databases/{id}`       | `databases:read` | Databases: label, database name and user.                                                                                             |
| `/cron-jobs`, `/cron-jobs/{id}`       | `cron:read`      | Cron jobs: schedule and command. One cron job also shows its last 20 runs with exit code and output.                                  |
| `/backups`, `/backups/{id}`           | `backups:read`   | Backups, newest first: kind, status (`running`, `ready` or `failed`), parts, size, the copy to your own storage and the last restore. |
| `/usage`                              | `usage:read`     | Usage readings of the last 90 days, newest first: disk, requests and data sent per web domain, mailbox and database.                  |
| `/usage/monthly`                      | `usage:read`     | The same per month (`period` as `YYYY-MM`), kept after the daily readings are gone.                                                   |

The web domain, DNS zone and mail domain lists take `?filter[domain]=example.com` to find one domain by its exact name.

Resources the panel applies to a server also carry `provisioning`: the `status` of the latest change sent to the node (`pending`, `dispatched`, `applied`, `already_applied`, `rejected`, `failed` or `degraded`) and when it finished, or null when nothing was sent yet.

**Never in a response:** mailbox and database passwords, and backup encryption keys. A password is shown only once, when it is created or changed.

An example, the web domains of one account:

```
curl -H "Authorization: Bearer lesta_..." \
     -H "Accept: application/json" \
     "https://panel.example.com/api/v1/accounts/ZQucS8keP4J7/web-domains?limit=20"
```

---

## Chapter 5: Good practice

- One token per script or machine, with its own name, so revoking one never breaks another.
- Only the abilities the script needs, and an account restriction whenever the script works on one customer.
- Keep tokens in your secret store or environment, never in a repository.
- Watch "last used" in the token list: a token used from an address you don't recognise should be revoked.
- Prefer a shorter expiry for tokens on machines you don't control.

---

## Chapter 6: What comes next

The API is built in steps. Each step will be added to this guide and to `docs/api/openapi.yaml` when it ships.

1. **Done:** tokens, `GET /user`, `GET /accounts`.
2. **Done:** reading everything an account has: web domains, DNS zones and records, mail domains and mailboxes, databases, cron jobs, backups, usage.
3. **Next:** creating, changing, suspending and deleting those. Changes to a server are applied by the node a moment later, so a write will answer `202 Accepted` with the resource in a pending state, and an operation you can poll. Writes will accept an `Idempotency-Key` header, so a script that retries after a timeout never creates something twice.
4. The platform administration endpoints (accounts, packages, nodes, whole-node backups), for tokens with the Platform administration abilities.
5. Checking every response against the OpenAPI description in the test suite, and the description shown here in the documentation.
