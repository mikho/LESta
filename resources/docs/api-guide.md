# LESta API Guide

The LESta API lets a script do what you do in the panel, as you. This guide covers what exists today: API tokens, how to call the API, reading and changing everything an account has, platform administration, and webhooks.

---

## Chapter 1: What the API is

- **The same panel, another door.** Every API call runs the same checks the panel does. A script using your token can never do more than you can in the panel, only less.
- **Version 1** lives under `/api/v1` on your panel's address, for example `https://panel.example.com/api/v1`. A future breaking change gets a new version (`/api/v2`); version 1 stays available for an announced period.
- **Every call in detail:** the **API Reference** in these docs lists each call with its parameters, body and answers. It is generated from the machine-readable contract, an OpenAPI 3.1 file in the LESta repository (`docs/api/openapi.yaml`), which you can also use to generate a client. The test suite checks every answer the API gives against that file.
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

### Responses

- `200` with the result under `data`; `201` when something new was made that needs no node (an account, a package).
- `202`: a change was accepted; the nodes apply it next (see "Changing things" below).

### Errors

Every error is a problem document ([RFC 9457](https://www.rfc-editor.org/rfc/rfc9457)), sent as `application/problem+json`:

```json
{
    "type": "urn:lesta:problem:missing-ability",
    "title": "Forbidden",
    "status": 403,
    "detail": "This token does not have the dns:write ability."
}
```

`detail` is for people and may change; a script should look at `status` and `type`. `type` names the problem when the API has a name for it, and is `about:blank` otherwise (then `title` is the status's own name):

| Status | `type` (after `urn:lesta:problem:`) | Meaning                                                                                                        |
| ------ | ----------------------------------- | -------------------------------------------------------------------------------------------------------------- |
| `400`  | `invalid-idempotency-key`           | The `Idempotency-Key` header is not 1 to 255 printable characters.                                             |
| `401`  | `invalid-token`                     | The token is missing, unknown, revoked or expired. Checked first, before anything in the address is looked up. |
| `403`  | `missing-ability`                   | The token lacks the ability this call needs.                                                                   |
| `403`  | `missing-permission`                | Your login lacks the platform permission an admin call needs (Chapter 5).                                      |
| `403`  | `account-restricted`                | The token is restricted to another account.                                                                    |
| `403`  | `account-suspended`                 | The account is suspended: it can be read, not changed.                                                         |
| `403`  | `about:blank`                       | You may not do this to this resource, for example in an account you are only a member of.                      |
| `404`  | `about:blank`                       | The resource does not exist, or belongs to another account than the one in the address.                        |
| `409`  | `idempotency-key-reused`            | The `Idempotency-Key` was already used with another request.                                                   |
| `409`  | `idempotency-key-in-progress`       | A request with this `Idempotency-Key` is still running.                                                        |
| `422`  | `validation`                        | The input is invalid; the field errors are under `errors`, in the same shape the panel uses.                   |
| `422`  | `quota-exceeded`                    | A package limit is reached, or the package allows none of this resource.                                       |
| `429`  | `too-many-failed-tokens`            | Too many failed tokens from your address (see Rate limits).                                                    |
| `429`  | `about:blank`                       | Too many requests; wait for `Retry-After` seconds.                                                             |
| `503`  | `no-capacity`                       | No server can take the resource right now, for example no node offers the web server you asked for.            |
| `500`  | `about:blank`                       | Something went wrong on the server. Nothing about it is in the answer; it is in the panel's log.               |

A validation problem:

```json
{
    "type": "urn:lesta:problem:validation",
    "title": "Unprocessable Content",
    "status": 422,
    "detail": "The given data was invalid.",
    "errors": { "domain": ["The domain field is required."] }
}
```

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

### Changing things

A change is checked and saved at once, then carried out on the server by the node a moment later. So a write answers **`202 Accepted`** with:

- `data`: the resource as it is now saved (null after a delete),
- `operation`: its `id`, its `status` (`pending`, `applied` or `failed`) and its `url`, also sent in the `Location` header.

Poll `GET /api/v1/operations/{id}` until `status` is `applied` or `failed`. A failed operation lists, per step, the node's error `code` and `message`. You only find the operations your own token made.

A call that changes nothing (suspending what is already suspended) answers `200` with `operation` null.

A suspended account can be read but not changed (`403`). The same rules as in the panel apply: only an account's owner changes it, the package limits hold, and every change is in the audit log, marked with the token it came through.

Every area has the same calls: `POST` on the list creates, `PATCH` on one changes the fields you send and keeps the rest, `DELETE` deletes, and `POST .../suspend` and `.../unsuspend` take it offline and back. Each needs the area's write ability.

| Area (ability)                                | Create with                                                                                                                                       | Change with                                                                                                                                       | Also                                          |
| --------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------- |
| `/web-domains` (`web:write`)                  | `domain`; optional `aliases`, `web_server` (`nginx`, `apache`), `php_version`, `ssl_mode` (`none`, `manual`, `lets_encrypt`), `web_template`      | the same, plus `waf_mode`, `waf_preset`, `waf_excluded_rules`, `hotlink_protection`, `hotlink_allowed_hosts`; sending `aliases` replaces them all |                                               |
| `/dns-zones` (`dns:write`)                    | `domain`; optional `ttl` (60 to 604800)                                                                                                           | `domain`, `ttl`                                                                                                                                   |                                               |
| `/dns-zones/{id}/records` (`dns:write`)       | `name` (`@` for the zone itself), `type` (`A`, `AAAA`, `CNAME`, `MX`, `TXT`, `NS`, `SRV`, `CAA`, `PTR`), `value`; `priority` for `MX` and `SRV`   | the same fields                                                                                                                                   | `GET` one record                              |
| `/mail-domains` (`mail:write`)                | `domain`; optional `antivirus_enabled`, `antispam_enabled`, `dkim_enabled`                                                                        | the same, plus `catchall_email` (a mailbox on the domain, or null)                                                                                | Deleting removes its mailboxes                |
| `/mail-domains/{id}/mailboxes` (`mail:write`) | `local_part`; optional `quota_mb`, `forward_to`, `forward_only`, `autoreply_enabled`, `autoreply_message`                                         | the same, except `local_part`                                                                                                                     | `GET` one mailbox; `POST .../rotate-password` |
| `/databases` (`databases:write`)              | `label` (lower case letters, digits and `_`, starting with a letter)                                                                              | cannot be changed                                                                                                                                 | `POST .../rotate-password`                    |
| `/cron-jobs` (`cron:write`)                   | `command`; optional `minute`, `hour`, `day_of_month`, `month`, `day_of_week` (`*` when left out)                                                  | any of them                                                                                                                                       |                                               |
| `/backups` (`backups:write`)                  | `parts` (`files`, `databases`, `mail`); `node` (the node's name, as backups show it) when the account has data on more than one; optional `label` | cannot be changed                                                                                                                                 | `POST .../restore`                            |

**Passwords.** Creating a mailbox or a database, and `rotate-password`, answer with `data.password`. It is shown in that answer only and never again; store it right away. If that answer is lost, rotate the password.

**Restoring a backup** needs `parts` and `"confirm": true`, since it overwrites the current data of those parts. A backup of the current data is taken first and the restore runs after it, so the answer has no operation: its `Location` is the backup, and `last_restore.status` shows how it goes (`running`, then `applied` or `failed`). A backup that is still running cannot be restored.

```
curl -X POST -H "Authorization: Bearer lesta_..." \
     -H "Accept: application/json" -H "Content-Type: application/json" \
     -H "Idempotency-Key: create-shop-2026-10-16" \
     -d '{"domain": "shop.example.com", "aliases": ["www.shop.example.com"]}' \
     "https://panel.example.com/api/v1/accounts/ZQucS8keP4J7/web-domains"
```

### Retrying safely

Send an `Idempotency-Key` header (any 1 to 255 printable characters, unique per change) with every `POST`. If the answer is lost and you send the same request with the same key again, you get the first answer back (marked `Idempotent-Replayed: true`) and nothing is created twice. Keys are kept 24 hours, per token. Only successful answers are kept, so after a `422` you can fix the request and send it with the same key. A replayed answer never repeats a password (`data.password` is null in it, and the password was never stored); rotate it if the first answer was lost. `PATCH` and `DELETE` are safe to repeat as they are.

---

## Chapter 5: Platform administration

For platform administrators, under `/api/v1/admin`. A token needs **Platform administration** read (`admin:read`) or write (`admin:write`), which only a platform administrator can tick, and every call also needs the same permission your role needs for that page in the panel. A call your role is not allowed answers `403` with `urn:lesta:problem:missing-permission`. Changes are audited like everything else, with the token.

| Call                                                                                | Permission                                              | What it does                                                                                                                                                                                             |
| ----------------------------------------------------------------------------------- | ------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `GET /admin/accounts`                                                               | `accounts.view_as_support`                              | Every customer account. `?filter[search]=` matches name or contact e-mail, `?filter[suspended]=true` or `false`.                                                                                         |
| `GET /admin/accounts/{id}`                                                          | `accounts.view_as_support`                              | One account, with its package, reseller and number of members.                                                                                                                                           |
| `POST /admin/accounts`                                                              | `accounts.create`                                       | Creates an account: `name`, `package` (an active package's id), `owner_name`, `owner_email`, optional `contact_email`. An owner without a login gets one and an e-mail to set a password. Answers `201`. |
| `PATCH /admin/accounts/{id}`                                                        | `accounts.update`                                       | Changes `name`, `contact_email` or `package`.                                                                                                                                                            |
| `POST /admin/accounts/{id}/suspend`, `/unsuspend`                                   | `accounts.suspend`, `accounts.unsuspend`                | Suspends or unsuspends the account and all its resources. Works on a suspended account.                                                                                                                  |
| `DELETE /admin/accounts/{id}`                                                       | `accounts.delete`                                       | Deletes the account and everything it has, on the nodes too. Send the account's id as `confirm`, for example `{"confirm": "ZQucS8keP4J7"}`.                                                              |
| `PUT /admin/accounts/{id}/reseller`, `DELETE`                                       | `accounts.update`                                       | Puts the account under a reseller account (`reseller_account_id`), or takes it away.                                                                                                                     |
| `GET /admin/packages`, `/admin/packages/{id}`                                       | `packages.view_any`, `packages.view`                    | Packages with their limits and how many accounts use them.                                                                                                                                               |
| `POST /admin/packages`, `PATCH`, `DELETE`                                           | `packages.create`, `packages.update`, `packages.delete` | `name`, `description`, `is_active` (false when left out, as in the panel). A package in use cannot be deleted.                                                                                           |
| `PUT /admin/packages/{id}/limits/{type}`                                            | `packages.update`                                       | Sets one limit: `{"limit_value": 10}`, or `null` for unlimited. Types: `web_domains`, `dns_zones`, `dns_records`, `mail_domains`, `mail_accounts`, `tenant_databases`, `cron_jobs`.                      |
| `GET /admin/nodes`, `/admin/nodes/{id}`                                             | `nodes.view_any`, `nodes.view`                          | Nodes with reachability, ping, services and capabilities.                                                                                                                                                |
| `POST /admin/nodes/{id}/suspend`, `/unsuspend`                                      | `nodes.suspend`, `nodes.unsuspend`                      | Takes a node out of service, or back.                                                                                                                                                                    |
| `GET /admin/node-backups`, `/admin/node-backups/{id}`                               | `backups.view_any`, `backups.view`                      | Whole-node backups, newest first; `?filter[node]=` takes a node's id or name.                                                                                                                            |
| `POST /admin/node-backups`, `DELETE /admin/node-backups/{id}`                       | `backups.create`, `backups.delete`                      | Starts a backup of a whole node (`node`, its id; optional `label`), or deletes one. Both answer `202` with an operation.                                                                                 |
| `GET /admin/web-domains`, `/dns-zones`, `/mail-domains`, `/databases`, `/cron-jobs` | `web_domains.view_any` and so on                        | Every customer's resources of that kind, each with its `account_id`; `?filter[account]=` and, for the domain lists, `?filter[domain]=`. Read only.                                                       |

In a package's `limits`, each type has `configured` and `limit`: `configured: false` means none of it may be created on the package (no limit set yet), `limit: null` means unlimited.

Suspending or deleting an account answers at once (`200`): the account itself is done, and each of its resources is changed on its node by its own operation, as in the panel.

**Not in the API**, on purpose: adding, enrolling and removing nodes, their capabilities and services, restoring a whole node and downloading its backup, members and roles, and anything a token can never do (Chapter 2). Use the panel for those.

---

## Chapter 6: Webhooks

Instead of polling, let LESta call you. Add a webhook under `Settings → Webhooks`: an account owner for their account, a platform administrator (`webhooks.manage`) for the whole platform.

**Events:**

| Event                | When                                                                                                                                | `data` holds                                           |
| -------------------- | ----------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------ |
| `operation.finished` | Every step of a change is done on its node, applied or failed. One per change, whether it was made in the panel or through the API. | `operation`: the same object as `GET /operations/{id}` |
| `backup.finished`    | An account backup is ready or failed; for platform webhooks also whole-node backups.                                                | `backup` (an account backup) or `node_backup`          |
| `restore.finished`   | A restore is done or failed, including when its safety backup failed.                                                               | `backup`, with `last_restore`                          |
| `ping`               | You pressed **Send a test**.                                                                                                        | `webhook`                                              |

An account's webhooks get that account's events; platform webhooks get every account's, and whole-node backups.

**The call** is a `POST` with a JSON body:

```json
{
    "id": "8b0c…",
    "event": "operation.finished",
    "created_at": "2026-10-17T09:30:00Z",
    "account_id": "ZQucS8keP4J7",
    "data": {
        "operation": {
            "id": "…",
            "action": "web_domain.created",
            "status": "applied",
            "steps": []
        }
    }
}
```

and these headers: `Lesta-Event` (the event), `Lesta-Delivery` (the `id`, the same on every retry: use it to ignore a repeat), and `Lesta-Signature`.

**Check the signature** before trusting a call. `Lesta-Signature` is `t=<unix time>,v1=<hex>`, where the hex is the HMAC-SHA256 of `<unix time>.<raw body>` with your webhook's signing secret. Compute it over the raw body exactly as received, compare in constant time, and refuse a time more than five minutes old. In PHP:

```php
[$t, $v1] = sscanf($_SERVER['HTTP_LESTA_SIGNATURE'], 't=%d,v1=%s');
$body = file_get_contents('php://input');
$ok = abs(time() - $t) < 300
    && hash_equals(hash_hmac('sha256', $t.'.'.$body, $secret), $v1);
```

**Answer** with any `2xx` within 10 seconds; do the real work afterwards. Anything else, a timeout or a redirect counts as failed, and the call is tried again after 1 minute, 5 minutes, 30 minutes, 2 hours, 6 hours and 12 hours. Calls can arrive late or, rarely, twice: rely on `Lesta-Delivery` and the event's own ids, not on the order. A webhook whose deliveries fail for good 10 times in a row switches itself off; **Send a test** switches it back on.

**Addresses:** HTTPS only, and only to public addresses: an address on a private, loopback or reserved network is refused when you add the webhook and again before every call, and the call goes to the address that was checked.

---

## Chapter 7: Good practice

- One token per script or machine, with its own name, so revoking one never breaks another.
- Only the abilities the script needs, and an account restriction whenever the script works on one customer.
- Keep tokens in your secret store or environment, never in a repository.
- Watch "last used" in the token list: a token used from an address you don't recognise should be revoked.
- Prefer a shorter expiry for tokens on machines you don't control.

---

## Chapter 8: What comes next

The API is built in steps. Each step will be added to this guide and to `docs/api/openapi.yaml` when it ships.

1. **Done:** tokens, `GET /user`, `GET /accounts`.
2. **Done:** reading everything an account has: web domains, DNS zones and records, mail domains and mailboxes, databases, cron jobs, backups, usage.
3. **Done:** creating, changing, suspending and deleting everything an account has, with `202 Accepted`, operations and `Idempotency-Key`.
4. **Done:** the platform administration endpoints (Chapter 5), and errors as problem documents.
5. **Done:** every answer checked against the OpenAPI description in the test suite, and the API Reference in these docs.
6. **Done:** webhooks (Chapter 6). **Later:** allowing a token only from given addresses.
