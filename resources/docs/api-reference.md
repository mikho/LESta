# LESta API Reference

Every call of the LESta API, generated from the OpenAPI description (`docs/api/openapi.yaml`, version v1). Paths are under `/api/v1` on your panel's address. The API Guide explains tokens, errors, paging, operations and retries; this page lists what each call takes and answers.

---

## Chapter 1: Your token, accounts and operations

### `GET /user`

The token's user and the token itself.

**Answers:**

- `200` The user. Data: [User](/docs/api-reference/chapter-14#user).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `GET /accounts`

The accounts the token reaches.

The user's own accounts, the accounts managed by a reseller account the user owns, and only the one account when the token is restricted to it.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `cursor` | query | no | string |
| `limit` | query | no | integer, at least 1, at most 100 |

**Answers:**

- `200` A page of accounts. Data: [Account](/docs/api-reference/chapter-14#account) (a page of them).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `GET /operations/{operation}`

A change this token made, and how far the nodes are with it.

Only changes made through this token are found; another token's or the panel's are a 404.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `operation` | path | yes | string (uuid) |

**Answers:**

- `200` The operation. Data: [Operation](/docs/api-reference/chapter-14#operation).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

---

## Chapter 2: Web domains

### `GET /accounts/{account}/web-domains`

The account's web domains.

Needs the web:read ability.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `filter[domain]` | query | no | Only the resource with exactly this domain (case does not matter). |
| `cursor` | query | no | string |
| `limit` | query | no | integer, at least 1, at most 100 |

**Answers:**

- `200` A page of results. Data: [WebDomain](/docs/api-reference/chapter-14#webdomain) (a page of them).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `POST /accounts/{account}/web-domains`

Create a web domain.

Needs the web:write ability. Refused (403) while the account is suspended.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `Idempotency-Key` | header | no | Any 1 to 255 printable characters. A retry with the same key and the same request gets the first answer again (with Idempotent-Replayed true) instead of running twice; kept 24 hours, per token. Only successful answers are kept. |

**Body:** [WebDomainCreate](/docs/api-reference/chapter-14#webdomaincreate)

**Answers:**

- `202` Accepted. The nodes apply the change next; poll the operation in Location. Data: [WebDomain](/docs/api-reference/chapter-14#webdomain).
- `400` The Idempotency-Key header is not 1 to 255 printable characters (urn:lesta:problem:invalid-idempotency-key). A [problem document](/docs/api-reference/chapter-14#problem).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `409` The Idempotency-Key was used with another request (idempotency-key-reused), or that request is still running (idempotency-key-in-progress). A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

### `GET /accounts/{account}/web-domains/{webDomain}`

One web domain.

Needs the web:read ability.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `webDomain` | path | yes | The web domain's id. |

**Answers:**

- `200` The resource. Data: [WebDomain](/docs/api-reference/chapter-14#webdomain).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `PATCH /accounts/{account}/web-domains/{webDomain}`

Change a web domain.

Needs the web:write ability. Refused (403) while the account is suspended. Fields left out keep their value.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `webDomain` | path | yes | The web domain's id. |

**Body:** [WebDomainUpdate](/docs/api-reference/chapter-14#webdomainupdate)

**Answers:**

- `202` Accepted. The nodes apply the change next; poll the operation in Location. Data: [WebDomain](/docs/api-reference/chapter-14#webdomain).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

### `DELETE /accounts/{account}/web-domains/{webDomain}`

Delete a web domain.

Needs the web:write ability. Refused (403) while the account is suspended.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `webDomain` | path | yes | The web domain's id. |

**Answers:**

- `202` Accepted. The nodes apply the change next; poll the operation in Location. Data: null.
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

### `POST /accounts/{account}/web-domains/{webDomain}/suspend`

Suspend a web domain.

Needs the web:write ability. Refused (403) while the account is suspended.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `webDomain` | path | yes | The web domain's id. |
| `Idempotency-Key` | header | no | Any 1 to 255 printable characters. A retry with the same key and the same request gets the first answer again (with Idempotent-Replayed true) instead of running twice; kept 24 hours, per token. Only successful answers are kept. |

**Answers:**

- `200` Nothing to change (for example, already suspended). Data: [WebDomain](/docs/api-reference/chapter-14#webdomain).
- `202` Accepted. The nodes apply the change next; poll the operation in Location. Data: [WebDomain](/docs/api-reference/chapter-14#webdomain).
- `400` The Idempotency-Key header is not 1 to 255 printable characters (urn:lesta:problem:invalid-idempotency-key). A [problem document](/docs/api-reference/chapter-14#problem).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `409` The Idempotency-Key was used with another request (idempotency-key-reused), or that request is still running (idempotency-key-in-progress). A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

### `POST /accounts/{account}/web-domains/{webDomain}/unsuspend`

Unsuspend a web domain.

Needs the web:write ability. Refused (403) while the account is suspended.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `webDomain` | path | yes | The web domain's id. |
| `Idempotency-Key` | header | no | Any 1 to 255 printable characters. A retry with the same key and the same request gets the first answer again (with Idempotent-Replayed true) instead of running twice; kept 24 hours, per token. Only successful answers are kept. |

**Answers:**

- `200` Nothing to change (for example, already suspended). Data: [WebDomain](/docs/api-reference/chapter-14#webdomain).
- `202` Accepted. The nodes apply the change next; poll the operation in Location. Data: [WebDomain](/docs/api-reference/chapter-14#webdomain).
- `400` The Idempotency-Key header is not 1 to 255 printable characters (urn:lesta:problem:invalid-idempotency-key). A [problem document](/docs/api-reference/chapter-14#problem).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `409` The Idempotency-Key was used with another request (idempotency-key-reused), or that request is still running (idempotency-key-in-progress). A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

---

## Chapter 3: DNS

### `GET /accounts/{account}/dns-zones`

The account's DNS zones.

Needs the dns:read ability.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `filter[domain]` | query | no | Only the resource with exactly this domain (case does not matter). |
| `cursor` | query | no | string |
| `limit` | query | no | integer, at least 1, at most 100 |

**Answers:**

- `200` A page of results. Data: [DnsZone](/docs/api-reference/chapter-14#dnszone) (a page of them).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `POST /accounts/{account}/dns-zones`

Create a DNS zone.

Needs the dns:write ability. Refused (403) while the account is suspended.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `Idempotency-Key` | header | no | Any 1 to 255 printable characters. A retry with the same key and the same request gets the first answer again (with Idempotent-Replayed true) instead of running twice; kept 24 hours, per token. Only successful answers are kept. |

**Body:** [DnsZoneCreate](/docs/api-reference/chapter-14#dnszonecreate)

**Answers:**

- `202` Accepted. The nodes apply the change next; poll the address in Location. Data: [DnsZone](/docs/api-reference/chapter-14#dnszone).
- `400` The Idempotency-Key header is not 1 to 255 printable characters (urn:lesta:problem:invalid-idempotency-key). A [problem document](/docs/api-reference/chapter-14#problem).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `409` The Idempotency-Key was used with another request (idempotency-key-reused), or that request is still running (idempotency-key-in-progress). A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

### `GET /accounts/{account}/dns-zones/{dnsZone}`

One DNS zone.

Needs the dns:read ability.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `dnsZone` | path | yes | The DNS zone's id. |

**Answers:**

- `200` The resource. Data: [DnsZone](/docs/api-reference/chapter-14#dnszone).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `PATCH /accounts/{account}/dns-zones/{dnsZone}`

Change a DNS zone.

Needs the dns:write ability. Refused (403) while the account is suspended. Fields left out keep their value.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `dnsZone` | path | yes | The DNS zone's id. |

**Body:** [DnsZoneUpdate](/docs/api-reference/chapter-14#dnszoneupdate)

**Answers:**

- `202` Accepted. The nodes apply the change next; poll the address in Location. Data: [DnsZone](/docs/api-reference/chapter-14#dnszone).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

### `DELETE /accounts/{account}/dns-zones/{dnsZone}`

Delete a DNS zone.

Needs the dns:write ability. Refused (403) while the account is suspended.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `dnsZone` | path | yes | The DNS zone's id. |

**Answers:**

- `202` Accepted. The nodes apply the change next; poll the address in Location. Data: null.
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

### `POST /accounts/{account}/dns-zones/{dnsZone}/suspend`

Suspend a DNS zone.

Needs the dns:write ability. Refused (403) while the account is suspended.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `dnsZone` | path | yes | The DNS zone's id. |
| `Idempotency-Key` | header | no | Any 1 to 255 printable characters. A retry with the same key and the same request gets the first answer again (with Idempotent-Replayed true) instead of running twice; kept 24 hours, per token. Only successful answers are kept. |

**Answers:**

- `200` Nothing to change (for example, already suspended). Data: [DnsZone](/docs/api-reference/chapter-14#dnszone).
- `202` Accepted. The nodes apply the change next; poll the address in Location. Data: [DnsZone](/docs/api-reference/chapter-14#dnszone).
- `400` The Idempotency-Key header is not 1 to 255 printable characters (urn:lesta:problem:invalid-idempotency-key). A [problem document](/docs/api-reference/chapter-14#problem).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `409` The Idempotency-Key was used with another request (idempotency-key-reused), or that request is still running (idempotency-key-in-progress). A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

### `POST /accounts/{account}/dns-zones/{dnsZone}/unsuspend`

Unsuspend a DNS zone.

Needs the dns:write ability. Refused (403) while the account is suspended.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `dnsZone` | path | yes | The DNS zone's id. |
| `Idempotency-Key` | header | no | Any 1 to 255 printable characters. A retry with the same key and the same request gets the first answer again (with Idempotent-Replayed true) instead of running twice; kept 24 hours, per token. Only successful answers are kept. |

**Answers:**

- `200` Nothing to change (for example, already suspended). Data: [DnsZone](/docs/api-reference/chapter-14#dnszone).
- `202` Accepted. The nodes apply the change next; poll the address in Location. Data: [DnsZone](/docs/api-reference/chapter-14#dnszone).
- `400` The Idempotency-Key header is not 1 to 255 printable characters (urn:lesta:problem:invalid-idempotency-key). A [problem document](/docs/api-reference/chapter-14#problem).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `409` The Idempotency-Key was used with another request (idempotency-key-reused), or that request is still running (idempotency-key-in-progress). A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

### `GET /accounts/{account}/dns-zones/{dnsZone}/records`

A zone's records.

Needs the dns:read ability.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `dnsZone` | path | yes | The DNS zone's id. |
| `cursor` | query | no | string |
| `limit` | query | no | integer, at least 1, at most 100 |

**Answers:**

- `200` A page of results. Data: [DnsRecord](/docs/api-reference/chapter-14#dnsrecord) (a page of them).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `POST /accounts/{account}/dns-zones/{dnsZone}/records`

Add a record to a zone.

Needs the dns:write ability. Refused (403) while the account is suspended.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `dnsZone` | path | yes | The DNS zone's id. |
| `Idempotency-Key` | header | no | Any 1 to 255 printable characters. A retry with the same key and the same request gets the first answer again (with Idempotent-Replayed true) instead of running twice; kept 24 hours, per token. Only successful answers are kept. |

**Body:** [DnsRecordCreate](/docs/api-reference/chapter-14#dnsrecordcreate)

**Answers:**

- `202` Accepted. The nodes apply the change next; poll the address in Location. Data: [DnsRecord](/docs/api-reference/chapter-14#dnsrecord).
- `400` The Idempotency-Key header is not 1 to 255 printable characters (urn:lesta:problem:invalid-idempotency-key). A [problem document](/docs/api-reference/chapter-14#problem).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `409` The Idempotency-Key was used with another request (idempotency-key-reused), or that request is still running (idempotency-key-in-progress). A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

### `GET /accounts/{account}/dns-zones/{dnsZone}/records/{record}`

One record.

Needs the dns:read ability.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `dnsZone` | path | yes | The DNS zone's id. |
| `record` | path | yes | The record's id. |

**Answers:**

- `200` The resource. Data: [DnsRecord](/docs/api-reference/chapter-14#dnsrecord).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `PATCH /accounts/{account}/dns-zones/{dnsZone}/records/{record}`

Change a record.

Needs the dns:write ability. Refused (403) while the account is suspended. Fields left out keep their value.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `dnsZone` | path | yes | The DNS zone's id. |
| `record` | path | yes | The record's id. |

**Body:** [DnsRecordUpdate](/docs/api-reference/chapter-14#dnsrecordupdate)

**Answers:**

- `202` Accepted. The nodes apply the change next; poll the address in Location. Data: [DnsRecord](/docs/api-reference/chapter-14#dnsrecord).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

### `DELETE /accounts/{account}/dns-zones/{dnsZone}/records/{record}`

Delete a record.

Needs the dns:write ability. Refused (403) while the account is suspended.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `dnsZone` | path | yes | The DNS zone's id. |
| `record` | path | yes | The record's id. |

**Answers:**

- `202` Accepted. The nodes apply the change next; poll the address in Location. Data: null.
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

### `POST /accounts/{account}/dns-zones/{dnsZone}/records/{record}/suspend`

Suspend a record.

Needs the dns:write ability. Refused (403) while the account is suspended.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `dnsZone` | path | yes | The DNS zone's id. |
| `record` | path | yes | The record's id. |
| `Idempotency-Key` | header | no | Any 1 to 255 printable characters. A retry with the same key and the same request gets the first answer again (with Idempotent-Replayed true) instead of running twice; kept 24 hours, per token. Only successful answers are kept. |

**Answers:**

- `200` Nothing to change (for example, already suspended). Data: [DnsRecord](/docs/api-reference/chapter-14#dnsrecord).
- `202` Accepted. The nodes apply the change next; poll the address in Location. Data: [DnsRecord](/docs/api-reference/chapter-14#dnsrecord).
- `400` The Idempotency-Key header is not 1 to 255 printable characters (urn:lesta:problem:invalid-idempotency-key). A [problem document](/docs/api-reference/chapter-14#problem).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `409` The Idempotency-Key was used with another request (idempotency-key-reused), or that request is still running (idempotency-key-in-progress). A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

### `POST /accounts/{account}/dns-zones/{dnsZone}/records/{record}/unsuspend`

Unsuspend a record.

Needs the dns:write ability. Refused (403) while the account is suspended.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `dnsZone` | path | yes | The DNS zone's id. |
| `record` | path | yes | The record's id. |
| `Idempotency-Key` | header | no | Any 1 to 255 printable characters. A retry with the same key and the same request gets the first answer again (with Idempotent-Replayed true) instead of running twice; kept 24 hours, per token. Only successful answers are kept. |

**Answers:**

- `200` Nothing to change (for example, already suspended). Data: [DnsRecord](/docs/api-reference/chapter-14#dnsrecord).
- `202` Accepted. The nodes apply the change next; poll the address in Location. Data: [DnsRecord](/docs/api-reference/chapter-14#dnsrecord).
- `400` The Idempotency-Key header is not 1 to 255 printable characters (urn:lesta:problem:invalid-idempotency-key). A [problem document](/docs/api-reference/chapter-14#problem).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `409` The Idempotency-Key was used with another request (idempotency-key-reused), or that request is still running (idempotency-key-in-progress). A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

---

## Chapter 4: Mail

### `GET /accounts/{account}/mail-domains`

The account's mail domains.

Needs the mail:read ability.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `filter[domain]` | query | no | Only the resource with exactly this domain (case does not matter). |
| `cursor` | query | no | string |
| `limit` | query | no | integer, at least 1, at most 100 |

**Answers:**

- `200` A page of results. Data: [MailDomain](/docs/api-reference/chapter-14#maildomain) (a page of them).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `POST /accounts/{account}/mail-domains`

Create a mail domain.

Needs the mail:write ability. Refused (403) while the account is suspended.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `Idempotency-Key` | header | no | Any 1 to 255 printable characters. A retry with the same key and the same request gets the first answer again (with Idempotent-Replayed true) instead of running twice; kept 24 hours, per token. Only successful answers are kept. |

**Body:** [MailDomainCreate](/docs/api-reference/chapter-14#maildomaincreate)

**Answers:**

- `202` Accepted. The nodes apply the change next; poll the address in Location. Data: [MailDomain](/docs/api-reference/chapter-14#maildomain).
- `400` The Idempotency-Key header is not 1 to 255 printable characters (urn:lesta:problem:invalid-idempotency-key). A [problem document](/docs/api-reference/chapter-14#problem).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `409` The Idempotency-Key was used with another request (idempotency-key-reused), or that request is still running (idempotency-key-in-progress). A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

### `GET /accounts/{account}/mail-domains/{mailDomain}`

One mail domain.

Needs the mail:read ability.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `mailDomain` | path | yes | The mail domain's id. |

**Answers:**

- `200` The resource. Data: [MailDomain](/docs/api-reference/chapter-14#maildomain).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `PATCH /accounts/{account}/mail-domains/{mailDomain}`

Change a mail domain.

Needs the mail:write ability. Refused (403) while the account is suspended. Fields left out keep their value.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `mailDomain` | path | yes | The mail domain's id. |

**Body:** [MailDomainUpdate](/docs/api-reference/chapter-14#maildomainupdate)

**Answers:**

- `202` Accepted. The nodes apply the change next; poll the address in Location. Data: [MailDomain](/docs/api-reference/chapter-14#maildomain).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

### `DELETE /accounts/{account}/mail-domains/{mailDomain}`

Delete a mail domain and its mailboxes.

Needs the mail:write ability. Refused (403) while the account is suspended.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `mailDomain` | path | yes | The mail domain's id. |

**Answers:**

- `202` Accepted. The nodes apply the change next; poll the address in Location. Data: null.
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

### `POST /accounts/{account}/mail-domains/{mailDomain}/suspend`

Suspend a mail domain.

Needs the mail:write ability. Refused (403) while the account is suspended.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `mailDomain` | path | yes | The mail domain's id. |
| `Idempotency-Key` | header | no | Any 1 to 255 printable characters. A retry with the same key and the same request gets the first answer again (with Idempotent-Replayed true) instead of running twice; kept 24 hours, per token. Only successful answers are kept. |

**Answers:**

- `200` Nothing to change (for example, already suspended). Data: [MailDomain](/docs/api-reference/chapter-14#maildomain).
- `202` Accepted. The nodes apply the change next; poll the address in Location. Data: [MailDomain](/docs/api-reference/chapter-14#maildomain).
- `400` The Idempotency-Key header is not 1 to 255 printable characters (urn:lesta:problem:invalid-idempotency-key). A [problem document](/docs/api-reference/chapter-14#problem).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `409` The Idempotency-Key was used with another request (idempotency-key-reused), or that request is still running (idempotency-key-in-progress). A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

### `POST /accounts/{account}/mail-domains/{mailDomain}/unsuspend`

Unsuspend a mail domain.

Needs the mail:write ability. Refused (403) while the account is suspended.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `mailDomain` | path | yes | The mail domain's id. |
| `Idempotency-Key` | header | no | Any 1 to 255 printable characters. A retry with the same key and the same request gets the first answer again (with Idempotent-Replayed true) instead of running twice; kept 24 hours, per token. Only successful answers are kept. |

**Answers:**

- `200` Nothing to change (for example, already suspended). Data: [MailDomain](/docs/api-reference/chapter-14#maildomain).
- `202` Accepted. The nodes apply the change next; poll the address in Location. Data: [MailDomain](/docs/api-reference/chapter-14#maildomain).
- `400` The Idempotency-Key header is not 1 to 255 printable characters (urn:lesta:problem:invalid-idempotency-key). A [problem document](/docs/api-reference/chapter-14#problem).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `409` The Idempotency-Key was used with another request (idempotency-key-reused), or that request is still running (idempotency-key-in-progress). A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

### `GET /accounts/{account}/mail-domains/{mailDomain}/mailboxes`

A mail domain's mailboxes.

Needs the mail:read ability.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `mailDomain` | path | yes | The mail domain's id. |
| `cursor` | query | no | string |
| `limit` | query | no | integer, at least 1, at most 100 |

**Answers:**

- `200` A page of results. Data: [Mailbox](/docs/api-reference/chapter-14#mailbox) (a page of them).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `POST /accounts/{account}/mail-domains/{mailDomain}/mailboxes`

Add a mailbox.

Needs the mail:write ability. Refused (403) while the account is suspended. The answer holds the password, once; a replayed answer has password null.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `mailDomain` | path | yes | The mail domain's id. |
| `Idempotency-Key` | header | no | Any 1 to 255 printable characters. A retry with the same key and the same request gets the first answer again (with Idempotent-Replayed true) instead of running twice; kept 24 hours, per token. Only successful answers are kept. |

**Body:** [MailboxCreate](/docs/api-reference/chapter-14#mailboxcreate)

**Answers:**

- `202` Accepted. The nodes apply the change next; poll the address in Location. Data: [MailboxWithPassword](/docs/api-reference/chapter-14#mailboxwithpassword).
- `400` The Idempotency-Key header is not 1 to 255 printable characters (urn:lesta:problem:invalid-idempotency-key). A [problem document](/docs/api-reference/chapter-14#problem).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `409` The Idempotency-Key was used with another request (idempotency-key-reused), or that request is still running (idempotency-key-in-progress). A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

### `GET /accounts/{account}/mail-domains/{mailDomain}/mailboxes/{mailbox}`

One mailbox.

Needs the mail:read ability.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `mailDomain` | path | yes | The mail domain's id. |
| `mailbox` | path | yes | The mailbox's id. |

**Answers:**

- `200` The resource. Data: [Mailbox](/docs/api-reference/chapter-14#mailbox).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `PATCH /accounts/{account}/mail-domains/{mailDomain}/mailboxes/{mailbox}`

Change a mailbox.

Needs the mail:write ability. Refused (403) while the account is suspended. Fields left out keep their value.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `mailDomain` | path | yes | The mail domain's id. |
| `mailbox` | path | yes | The mailbox's id. |

**Body:** [MailboxUpdate](/docs/api-reference/chapter-14#mailboxupdate)

**Answers:**

- `202` Accepted. The nodes apply the change next; poll the address in Location. Data: [Mailbox](/docs/api-reference/chapter-14#mailbox).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

### `DELETE /accounts/{account}/mail-domains/{mailDomain}/mailboxes/{mailbox}`

Delete a mailbox.

Needs the mail:write ability. Refused (403) while the account is suspended.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `mailDomain` | path | yes | The mail domain's id. |
| `mailbox` | path | yes | The mailbox's id. |

**Answers:**

- `202` Accepted. The nodes apply the change next; poll the address in Location. Data: null.
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

### `POST /accounts/{account}/mail-domains/{mailDomain}/mailboxes/{mailbox}/suspend`

Suspend a mailbox.

Needs the mail:write ability. Refused (403) while the account is suspended.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `mailDomain` | path | yes | The mail domain's id. |
| `mailbox` | path | yes | The mailbox's id. |
| `Idempotency-Key` | header | no | Any 1 to 255 printable characters. A retry with the same key and the same request gets the first answer again (with Idempotent-Replayed true) instead of running twice; kept 24 hours, per token. Only successful answers are kept. |

**Answers:**

- `200` Nothing to change (for example, already suspended). Data: [Mailbox](/docs/api-reference/chapter-14#mailbox).
- `202` Accepted. The nodes apply the change next; poll the address in Location. Data: [Mailbox](/docs/api-reference/chapter-14#mailbox).
- `400` The Idempotency-Key header is not 1 to 255 printable characters (urn:lesta:problem:invalid-idempotency-key). A [problem document](/docs/api-reference/chapter-14#problem).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `409` The Idempotency-Key was used with another request (idempotency-key-reused), or that request is still running (idempotency-key-in-progress). A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

### `POST /accounts/{account}/mail-domains/{mailDomain}/mailboxes/{mailbox}/unsuspend`

Unsuspend a mailbox.

Needs the mail:write ability. Refused (403) while the account is suspended.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `mailDomain` | path | yes | The mail domain's id. |
| `mailbox` | path | yes | The mailbox's id. |
| `Idempotency-Key` | header | no | Any 1 to 255 printable characters. A retry with the same key and the same request gets the first answer again (with Idempotent-Replayed true) instead of running twice; kept 24 hours, per token. Only successful answers are kept. |

**Answers:**

- `200` Nothing to change (for example, already suspended). Data: [Mailbox](/docs/api-reference/chapter-14#mailbox).
- `202` Accepted. The nodes apply the change next; poll the address in Location. Data: [Mailbox](/docs/api-reference/chapter-14#mailbox).
- `400` The Idempotency-Key header is not 1 to 255 printable characters (urn:lesta:problem:invalid-idempotency-key). A [problem document](/docs/api-reference/chapter-14#problem).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `409` The Idempotency-Key was used with another request (idempotency-key-reused), or that request is still running (idempotency-key-in-progress). A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

### `POST /accounts/{account}/mail-domains/{mailDomain}/mailboxes/{mailbox}/rotate-password`

Give a mailbox a new password.

Needs the mail:write ability. Refused (403) while the account is suspended. The answer holds the new password, once.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `mailDomain` | path | yes | The mail domain's id. |
| `mailbox` | path | yes | The mailbox's id. |
| `Idempotency-Key` | header | no | Any 1 to 255 printable characters. A retry with the same key and the same request gets the first answer again (with Idempotent-Replayed true) instead of running twice; kept 24 hours, per token. Only successful answers are kept. |

**Answers:**

- `202` Accepted. The nodes apply the change next; poll the address in Location. Data: [MailboxWithPassword](/docs/api-reference/chapter-14#mailboxwithpassword).
- `400` The Idempotency-Key header is not 1 to 255 printable characters (urn:lesta:problem:invalid-idempotency-key). A [problem document](/docs/api-reference/chapter-14#problem).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `409` The Idempotency-Key was used with another request (idempotency-key-reused), or that request is still running (idempotency-key-in-progress). A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

---

## Chapter 5: Databases

### `GET /accounts/{account}/databases`

The account's databases.

Needs the databases:read ability.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `cursor` | query | no | string |
| `limit` | query | no | integer, at least 1, at most 100 |

**Answers:**

- `200` A page of results. Data: [Database](/docs/api-reference/chapter-14#database) (a page of them).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `POST /accounts/{account}/databases`

Create a database.

Needs the databases:write ability. Refused (403) while the account is suspended. The answer holds the database user's password, once; a replayed answer has password null.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `Idempotency-Key` | header | no | Any 1 to 255 printable characters. A retry with the same key and the same request gets the first answer again (with Idempotent-Replayed true) instead of running twice; kept 24 hours, per token. Only successful answers are kept. |

**Body:** [DatabaseCreate](/docs/api-reference/chapter-14#databasecreate)

**Answers:**

- `202` Accepted. The nodes apply the change next; poll the address in Location. Data: [DatabaseWithPassword](/docs/api-reference/chapter-14#databasewithpassword).
- `400` The Idempotency-Key header is not 1 to 255 printable characters (urn:lesta:problem:invalid-idempotency-key). A [problem document](/docs/api-reference/chapter-14#problem).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `409` The Idempotency-Key was used with another request (idempotency-key-reused), or that request is still running (idempotency-key-in-progress). A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

### `GET /accounts/{account}/databases/{database}`

One database.

Needs the databases:read ability.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `database` | path | yes | The database's id. |

**Answers:**

- `200` The resource. Data: [Database](/docs/api-reference/chapter-14#database).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `DELETE /accounts/{account}/databases/{database}`

Delete a database.

Needs the databases:write ability. Refused (403) while the account is suspended.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `database` | path | yes | The database's id. |

**Answers:**

- `202` Accepted. The nodes apply the change next; poll the address in Location. Data: null.
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

### `POST /accounts/{account}/databases/{database}/suspend`

Suspend a database.

Needs the databases:write ability. Refused (403) while the account is suspended.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `database` | path | yes | The database's id. |
| `Idempotency-Key` | header | no | Any 1 to 255 printable characters. A retry with the same key and the same request gets the first answer again (with Idempotent-Replayed true) instead of running twice; kept 24 hours, per token. Only successful answers are kept. |

**Answers:**

- `200` Nothing to change (for example, already suspended). Data: [Database](/docs/api-reference/chapter-14#database).
- `202` Accepted. The nodes apply the change next; poll the address in Location. Data: [Database](/docs/api-reference/chapter-14#database).
- `400` The Idempotency-Key header is not 1 to 255 printable characters (urn:lesta:problem:invalid-idempotency-key). A [problem document](/docs/api-reference/chapter-14#problem).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `409` The Idempotency-Key was used with another request (idempotency-key-reused), or that request is still running (idempotency-key-in-progress). A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

### `POST /accounts/{account}/databases/{database}/unsuspend`

Unsuspend a database.

Needs the databases:write ability. Refused (403) while the account is suspended.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `database` | path | yes | The database's id. |
| `Idempotency-Key` | header | no | Any 1 to 255 printable characters. A retry with the same key and the same request gets the first answer again (with Idempotent-Replayed true) instead of running twice; kept 24 hours, per token. Only successful answers are kept. |

**Answers:**

- `200` Nothing to change (for example, already suspended). Data: [Database](/docs/api-reference/chapter-14#database).
- `202` Accepted. The nodes apply the change next; poll the address in Location. Data: [Database](/docs/api-reference/chapter-14#database).
- `400` The Idempotency-Key header is not 1 to 255 printable characters (urn:lesta:problem:invalid-idempotency-key). A [problem document](/docs/api-reference/chapter-14#problem).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `409` The Idempotency-Key was used with another request (idempotency-key-reused), or that request is still running (idempotency-key-in-progress). A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

### `POST /accounts/{account}/databases/{database}/rotate-password`

Give the database user a new password.

Needs the databases:write ability. Refused (403) while the account is suspended. The answer holds the new password, once.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `database` | path | yes | The database's id. |
| `Idempotency-Key` | header | no | Any 1 to 255 printable characters. A retry with the same key and the same request gets the first answer again (with Idempotent-Replayed true) instead of running twice; kept 24 hours, per token. Only successful answers are kept. |

**Answers:**

- `202` Accepted. The nodes apply the change next; poll the address in Location. Data: [DatabaseWithPassword](/docs/api-reference/chapter-14#databasewithpassword).
- `400` The Idempotency-Key header is not 1 to 255 printable characters (urn:lesta:problem:invalid-idempotency-key). A [problem document](/docs/api-reference/chapter-14#problem).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `409` The Idempotency-Key was used with another request (idempotency-key-reused), or that request is still running (idempotency-key-in-progress). A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

### `GET /accounts/{account}/databases/{database}/users`

The database's extra users.

Needs the databases:read ability.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `database` | path | yes | The database's id. |
| `cursor` | query | no | string |
| `limit` | query | no | integer, at least 1, at most 100 |

**Answers:**

- `200` A page of results. Data: [DatabaseUser](/docs/api-reference/chapter-14#databaseuser) (a page of them).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `POST /accounts/{account}/databases/{database}/users`

Add a user to the database.

Needs the databases:write ability. Refused (403) while the account is suspended. The user is named after the database and the label (database_name, an underscore, the label); at most 20 per database. The answer holds its password, once; the panel does not keep it. The operation is the database's.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `database` | path | yes | The database's id. |
| `Idempotency-Key` | header | no | Any 1 to 255 printable characters. A retry with the same key and the same request gets the first answer again (with Idempotent-Replayed true) instead of running twice; kept 24 hours, per token. Only successful answers are kept. |

**Body:** [DatabaseUserCreate](/docs/api-reference/chapter-14#databaseusercreate)

**Answers:**

- `202` Accepted. The nodes apply the change next; poll the address in Location. Data: [DatabaseUserWithPassword](/docs/api-reference/chapter-14#databaseuserwithpassword).
- `400` The Idempotency-Key header is not 1 to 255 printable characters (urn:lesta:problem:invalid-idempotency-key). A [problem document](/docs/api-reference/chapter-14#problem).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `409` The Idempotency-Key was used with another request (idempotency-key-reused), or that request is still running (idempotency-key-in-progress). A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

### `GET /accounts/{account}/databases/{database}/users/{user}`

One extra user.

Needs the databases:read ability.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `database` | path | yes | The database's id. |
| `user` | path | yes | The extra user's id. |

**Answers:**

- `200` The resource. Data: [DatabaseUser](/docs/api-reference/chapter-14#databaseuser).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `PATCH /accounts/{account}/databases/{database}/users/{user}`

Change a user's access.

Needs the databases:write ability. Refused (403) while the account is suspended. The label and user name stay as they are.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `database` | path | yes | The database's id. |
| `user` | path | yes | The extra user's id. |

**Body:** [DatabaseUserUpdate](/docs/api-reference/chapter-14#databaseuserupdate)

**Answers:**

- `202` Accepted. The nodes apply the change next; poll the address in Location. Data: [DatabaseUser](/docs/api-reference/chapter-14#databaseuser).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

### `DELETE /accounts/{account}/databases/{database}/users/{user}`

Remove a user from the database.

Needs the databases:write ability. Refused (403) while the account is suspended.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `database` | path | yes | The database's id. |
| `user` | path | yes | The extra user's id. |

**Answers:**

- `202` Accepted. The nodes apply the change next; poll the address in Location. Data: null.
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

### `POST /accounts/{account}/databases/{database}/users/{user}/rotate-password`

Give an extra user a new password.

Needs the databases:write ability. Refused (403) while the account is suspended. The answer holds the new password, once.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `database` | path | yes | The database's id. |
| `user` | path | yes | The extra user's id. |
| `Idempotency-Key` | header | no | Any 1 to 255 printable characters. A retry with the same key and the same request gets the first answer again (with Idempotent-Replayed true) instead of running twice; kept 24 hours, per token. Only successful answers are kept. |

**Answers:**

- `202` Accepted. The nodes apply the change next; poll the address in Location. Data: [DatabaseUserWithPassword](/docs/api-reference/chapter-14#databaseuserwithpassword).
- `400` The Idempotency-Key header is not 1 to 255 printable characters (urn:lesta:problem:invalid-idempotency-key). A [problem document](/docs/api-reference/chapter-14#problem).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `409` The Idempotency-Key was used with another request (idempotency-key-reused), or that request is still running (idempotency-key-in-progress). A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

---

## Chapter 6: Cron jobs

### `GET /accounts/{account}/cron-jobs`

The account's cron jobs.

Needs the cron:read ability.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `cursor` | query | no | string |
| `limit` | query | no | integer, at least 1, at most 100 |

**Answers:**

- `200` A page of results. Data: [CronJob](/docs/api-reference/chapter-14#cronjob) (a page of them).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `POST /accounts/{account}/cron-jobs`

Create a cron job.

Needs the cron:write ability. Refused (403) while the account is suspended.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `Idempotency-Key` | header | no | Any 1 to 255 printable characters. A retry with the same key and the same request gets the first answer again (with Idempotent-Replayed true) instead of running twice; kept 24 hours, per token. Only successful answers are kept. |

**Body:** [CronJobCreate](/docs/api-reference/chapter-14#cronjobcreate)

**Answers:**

- `202` Accepted. The nodes apply the change next; poll the address in Location. Data: [CronJob](/docs/api-reference/chapter-14#cronjob).
- `400` The Idempotency-Key header is not 1 to 255 printable characters (urn:lesta:problem:invalid-idempotency-key). A [problem document](/docs/api-reference/chapter-14#problem).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `409` The Idempotency-Key was used with another request (idempotency-key-reused), or that request is still running (idempotency-key-in-progress). A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

### `GET /accounts/{account}/cron-jobs/{cronJob}`

One cron job, with its last 20 runs.

Needs the cron:read ability.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `cronJob` | path | yes | The cron job's id. |

**Answers:**

- `200` The resource. Data: [CronJob](/docs/api-reference/chapter-14#cronjob).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `PATCH /accounts/{account}/cron-jobs/{cronJob}`

Change a cron job.

Needs the cron:write ability. Refused (403) while the account is suspended. Fields left out keep their value.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `cronJob` | path | yes | The cron job's id. |

**Body:** [CronJobUpdate](/docs/api-reference/chapter-14#cronjobupdate)

**Answers:**

- `202` Accepted. The nodes apply the change next; poll the address in Location. Data: [CronJob](/docs/api-reference/chapter-14#cronjob).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

### `DELETE /accounts/{account}/cron-jobs/{cronJob}`

Delete a cron job.

Needs the cron:write ability. Refused (403) while the account is suspended.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `cronJob` | path | yes | The cron job's id. |

**Answers:**

- `202` Accepted. The nodes apply the change next; poll the address in Location. Data: null.
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

### `POST /accounts/{account}/cron-jobs/{cronJob}/suspend`

Suspend a cron job.

Needs the cron:write ability. Refused (403) while the account is suspended.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `cronJob` | path | yes | The cron job's id. |
| `Idempotency-Key` | header | no | Any 1 to 255 printable characters. A retry with the same key and the same request gets the first answer again (with Idempotent-Replayed true) instead of running twice; kept 24 hours, per token. Only successful answers are kept. |

**Answers:**

- `200` Nothing to change (for example, already suspended). Data: [CronJob](/docs/api-reference/chapter-14#cronjob).
- `202` Accepted. The nodes apply the change next; poll the address in Location. Data: [CronJob](/docs/api-reference/chapter-14#cronjob).
- `400` The Idempotency-Key header is not 1 to 255 printable characters (urn:lesta:problem:invalid-idempotency-key). A [problem document](/docs/api-reference/chapter-14#problem).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `409` The Idempotency-Key was used with another request (idempotency-key-reused), or that request is still running (idempotency-key-in-progress). A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

### `POST /accounts/{account}/cron-jobs/{cronJob}/unsuspend`

Unsuspend a cron job.

Needs the cron:write ability. Refused (403) while the account is suspended.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `cronJob` | path | yes | The cron job's id. |
| `Idempotency-Key` | header | no | Any 1 to 255 printable characters. A retry with the same key and the same request gets the first answer again (with Idempotent-Replayed true) instead of running twice; kept 24 hours, per token. Only successful answers are kept. |

**Answers:**

- `200` Nothing to change (for example, already suspended). Data: [CronJob](/docs/api-reference/chapter-14#cronjob).
- `202` Accepted. The nodes apply the change next; poll the address in Location. Data: [CronJob](/docs/api-reference/chapter-14#cronjob).
- `400` The Idempotency-Key header is not 1 to 255 printable characters (urn:lesta:problem:invalid-idempotency-key). A [problem document](/docs/api-reference/chapter-14#problem).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `409` The Idempotency-Key was used with another request (idempotency-key-reused), or that request is still running (idempotency-key-in-progress). A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

---

## Chapter 7: Backups

### `GET /accounts/{account}/backups`

The account's backups, newest first.

Needs the backups:read ability.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `cursor` | query | no | string |
| `limit` | query | no | integer, at least 1, at most 100 |

**Answers:**

- `200` A page of results. Data: [Backup](/docs/api-reference/chapter-14#backup) (a page of them).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `POST /accounts/{account}/backups`

Back up the account on one node.

Needs the backups:write ability. Refused (403) while the account is suspended.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `Idempotency-Key` | header | no | Any 1 to 255 printable characters. A retry with the same key and the same request gets the first answer again (with Idempotent-Replayed true) instead of running twice; kept 24 hours, per token. Only successful answers are kept. |

**Body:** [BackupCreate](/docs/api-reference/chapter-14#backupcreate)

**Answers:**

- `202` Accepted. The nodes apply the change next; poll the address in Location. Data: [Backup](/docs/api-reference/chapter-14#backup).
- `400` The Idempotency-Key header is not 1 to 255 printable characters (urn:lesta:problem:invalid-idempotency-key). A [problem document](/docs/api-reference/chapter-14#problem).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `409` The Idempotency-Key was used with another request (idempotency-key-reused), or that request is still running (idempotency-key-in-progress). A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

### `GET /accounts/{account}/backups/{backup}`

One backup.

Needs the backups:read ability.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `backup` | path | yes | The backup's id. |

**Answers:**

- `200` The resource. Data: [Backup](/docs/api-reference/chapter-14#backup).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `DELETE /accounts/{account}/backups/{backup}`

Delete a backup.

Needs the backups:write ability. Refused (403) while the account is suspended.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `backup` | path | yes | The backup's id. |

**Answers:**

- `202` Accepted. The nodes apply the change next; poll the address in Location. Data: null.
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

### `POST /accounts/{account}/backups/{backup}/restore`

Restore parts of a backup over the current data.

Needs the backups:write ability. Refused (403) while the account is suspended. A backup of the current data is taken first and the restore runs after it, so operation is null and Location is the backup; follow last_restore.status.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `backup` | path | yes | The backup's id. |
| `Idempotency-Key` | header | no | Any 1 to 255 printable characters. A retry with the same key and the same request gets the first answer again (with Idempotent-Replayed true) instead of running twice; kept 24 hours, per token. Only successful answers are kept. |

**Body:** [BackupRestore](/docs/api-reference/chapter-14#backuprestore)

**Answers:**

- `202` Accepted. The nodes apply the change next; poll the address in Location. Data: [Backup](/docs/api-reference/chapter-14#backup).
- `400` The Idempotency-Key header is not 1 to 255 printable characters (urn:lesta:problem:invalid-idempotency-key). A [problem document](/docs/api-reference/chapter-14#problem).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `409` The Idempotency-Key was used with another request (idempotency-key-reused), or that request is still running (idempotency-key-in-progress). A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).
- `503` No server can take the resource right now (urn:lesta:problem:no-capacity). A [problem document](/docs/api-reference/chapter-14#problem).

---

## Chapter 8: Usage

### `GET /accounts/{account}/usage`

Usage readings of the last 90 days, newest first.

Needs the usage:read ability.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `cursor` | query | no | string |
| `limit` | query | no | integer, at least 1, at most 100 |

**Answers:**

- `200` A page of results. Data: [UsageSnapshot](/docs/api-reference/chapter-14#usagesnapshot) (a page of them).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `GET /accounts/{account}/usage/monthly`

Usage per resource and month, newest first.

Needs the usage:read ability.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `cursor` | query | no | string |
| `limit` | query | no | integer, at least 1, at most 100 |

**Answers:**

- `200` A page of results. Data: [UsageMonth](/docs/api-reference/chapter-14#usagemonth) (a page of them).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

---

## Chapter 9: Administration: accounts

### `GET /admin/accounts`

Every customer account.

Needs the admin:read ability and the accounts.view_as_support permission. The hidden platform account is never listed.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `filter[search]` | query | no | Part of the name or contact e-mail. |
| `filter[suspended]` | query | no | true for suspended accounts only, false for the others. |
| `cursor` | query | no | string |
| `limit` | query | no | integer, at least 1, at most 100 |

**Answers:**

- `200` A page of results. Data: [Account](/docs/api-reference/chapter-14#account) (a page of them).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `POST /admin/accounts`

Create an account and its owner.

Needs the admin:write ability and the accounts.create permission. An owner without a login gets one and an e-mail to set a password.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `Idempotency-Key` | header | no | Any 1 to 255 printable characters. A retry with the same key and the same request gets the first answer again (with Idempotent-Replayed true) instead of running twice; kept 24 hours, per token. Only successful answers are kept. |

**Body:** [AdminAccountCreate](/docs/api-reference/chapter-14#adminaccountcreate)

**Answers:**

- `201` Created. Data: [Account](/docs/api-reference/chapter-14#account).
- `400` The Idempotency-Key header is not 1 to 255 printable characters (urn:lesta:problem:invalid-idempotency-key). A [problem document](/docs/api-reference/chapter-14#problem).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `409` The Idempotency-Key was used with another request (idempotency-key-reused), or that request is still running (idempotency-key-in-progress). A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `GET /admin/accounts/{account}`

One account.

Needs the admin:read ability and the accounts.view_as_support permission.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |

**Answers:**

- `200` The resource. Data: [Account](/docs/api-reference/chapter-14#account).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `PATCH /admin/accounts/{account}`

Change an account.

Needs the admin:write ability and the accounts.update permission. Fields left out keep their value.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |

**Body:** [AdminAccountUpdate](/docs/api-reference/chapter-14#adminaccountupdate)

**Answers:**

- `200` Done. Data: [Account](/docs/api-reference/chapter-14#account).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `DELETE /admin/accounts/{account}`

Delete an account with everything it has.

Needs the admin:write ability and the accounts.delete permission. Its sites, mail, databases, backups and logins on the nodes go too; each resource is removed by its own operation.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |

**Body:** [AdminAccountDelete](/docs/api-reference/chapter-14#adminaccountdelete)

**Answers:**

- `200` Done. Data: null.
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `POST /admin/accounts/{account}/suspend`

Suspend an account and its resources.

Needs the admin:write ability and the accounts.suspend permission. Each resource is suspended on its node by its own operation.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `Idempotency-Key` | header | no | Any 1 to 255 printable characters. A retry with the same key and the same request gets the first answer again (with Idempotent-Replayed true) instead of running twice; kept 24 hours, per token. Only successful answers are kept. |

**Answers:**

- `200` Done. Data: [Account](/docs/api-reference/chapter-14#account).
- `400` The Idempotency-Key header is not 1 to 255 printable characters (urn:lesta:problem:invalid-idempotency-key). A [problem document](/docs/api-reference/chapter-14#problem).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `409` The Idempotency-Key was used with another request (idempotency-key-reused), or that request is still running (idempotency-key-in-progress). A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `POST /admin/accounts/{account}/unsuspend`

Unsuspend an account and its resources.

Needs the admin:write ability and the accounts.unsuspend permission.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |
| `Idempotency-Key` | header | no | Any 1 to 255 printable characters. A retry with the same key and the same request gets the first answer again (with Idempotent-Replayed true) instead of running twice; kept 24 hours, per token. Only successful answers are kept. |

**Answers:**

- `200` Done. Data: [Account](/docs/api-reference/chapter-14#account).
- `400` The Idempotency-Key header is not 1 to 255 printable characters (urn:lesta:problem:invalid-idempotency-key). A [problem document](/docs/api-reference/chapter-14#problem).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `409` The Idempotency-Key was used with another request (idempotency-key-reused), or that request is still running (idempotency-key-in-progress). A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `PUT /admin/accounts/{account}/reseller`

Put an account under a reseller account.

Needs the admin:write ability and the accounts.update permission.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |

**Body:** [AdminAssignReseller](/docs/api-reference/chapter-14#adminassignreseller)

**Answers:**

- `200` Done. Data: [Account](/docs/api-reference/chapter-14#account).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `DELETE /admin/accounts/{account}/reseller`

Take an account from its reseller.

Needs the admin:write ability and the accounts.update permission.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `account` | path | yes | The account's id from GET /accounts. |

**Answers:**

- `200` Done. Data: [Account](/docs/api-reference/chapter-14#account).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

---

## Chapter 10: Administration: packages

### `GET /admin/packages`

Every hosting package.

Needs the admin:read ability and the packages.view_any permission.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `cursor` | query | no | string |
| `limit` | query | no | integer, at least 1, at most 100 |

**Answers:**

- `200` A page of results. Data: [Package](/docs/api-reference/chapter-14#package) (a page of them).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `POST /admin/packages`

Create a package.

Needs the admin:write ability and the packages.create permission. A new package has no limits set.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `Idempotency-Key` | header | no | Any 1 to 255 printable characters. A retry with the same key and the same request gets the first answer again (with Idempotent-Replayed true) instead of running twice; kept 24 hours, per token. Only successful answers are kept. |

**Body:** [PackageCreate](/docs/api-reference/chapter-14#packagecreate)

**Answers:**

- `201` Created. Data: [Package](/docs/api-reference/chapter-14#package).
- `400` The Idempotency-Key header is not 1 to 255 printable characters (urn:lesta:problem:invalid-idempotency-key). A [problem document](/docs/api-reference/chapter-14#problem).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `409` The Idempotency-Key was used with another request (idempotency-key-reused), or that request is still running (idempotency-key-in-progress). A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `GET /admin/packages/{package}`

One package.

Needs the admin:read ability and the packages.view permission.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `package` | path | yes | The package's id. |

**Answers:**

- `200` The resource. Data: [Package](/docs/api-reference/chapter-14#package).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `PATCH /admin/packages/{package}`

Change a package.

Needs the admin:write ability and the packages.update permission. Fields left out keep their value.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `package` | path | yes | The package's id. |

**Body:** [PackageUpdate](/docs/api-reference/chapter-14#packageupdate)

**Answers:**

- `200` Done. Data: [Package](/docs/api-reference/chapter-14#package).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `DELETE /admin/packages/{package}`

Delete a package no account uses.

Needs the admin:write ability and the packages.delete permission.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `package` | path | yes | The package's id. |

**Answers:**

- `200` Done. Data: null.
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `PUT /admin/packages/{package}/limits/{resourceType}`

Set one limit of a package.

Needs the admin:write ability and the packages.update permission.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `package` | path | yes | The package's id. |
| `resourceType` | path | yes | string: `web_domains`, `dns_zones`, `dns_records`, `mail_domains`, `mail_accounts`, `tenant_databases`, `cron_jobs` |

**Body:** [PackageLimit](/docs/api-reference/chapter-14#packagelimit)

**Answers:**

- `200` Done. Data: [Package](/docs/api-reference/chapter-14#package).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

---

## Chapter 11: Administration: nodes

### `GET /admin/nodes`

Every node.

Needs the admin:read ability and the nodes.view_any permission.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `cursor` | query | no | string |
| `limit` | query | no | integer, at least 1, at most 100 |

**Answers:**

- `200` A page of results. Data: [Node](/docs/api-reference/chapter-14#node) (a page of them).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `GET /admin/nodes/{node}`

One node.

Needs the admin:read ability and the nodes.view permission.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `node` | path | yes | The node's id. |

**Answers:**

- `200` The resource. Data: [Node](/docs/api-reference/chapter-14#node).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `POST /admin/nodes/{node}/suspend`

Take a node out of service.

Needs the admin:write ability and the nodes.suspend permission.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `node` | path | yes | The node's id. |
| `Idempotency-Key` | header | no | Any 1 to 255 printable characters. A retry with the same key and the same request gets the first answer again (with Idempotent-Replayed true) instead of running twice; kept 24 hours, per token. Only successful answers are kept. |

**Answers:**

- `200` Done. Data: [Node](/docs/api-reference/chapter-14#node).
- `400` The Idempotency-Key header is not 1 to 255 printable characters (urn:lesta:problem:invalid-idempotency-key). A [problem document](/docs/api-reference/chapter-14#problem).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `409` The Idempotency-Key was used with another request (idempotency-key-reused), or that request is still running (idempotency-key-in-progress). A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `POST /admin/nodes/{node}/unsuspend`

Put a node back in service.

Needs the admin:write ability and the nodes.unsuspend permission.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `node` | path | yes | The node's id. |
| `Idempotency-Key` | header | no | Any 1 to 255 printable characters. A retry with the same key and the same request gets the first answer again (with Idempotent-Replayed true) instead of running twice; kept 24 hours, per token. Only successful answers are kept. |

**Answers:**

- `200` Done. Data: [Node](/docs/api-reference/chapter-14#node).
- `400` The Idempotency-Key header is not 1 to 255 printable characters (urn:lesta:problem:invalid-idempotency-key). A [problem document](/docs/api-reference/chapter-14#problem).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `409` The Idempotency-Key was used with another request (idempotency-key-reused), or that request is still running (idempotency-key-in-progress). A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

---

## Chapter 12: Administration: whole-node backups

### `GET /admin/node-backups`

Whole-node backups, newest first.

Needs the admin:read ability and the backups.view_any permission.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `filter[node]` | query | no | The node's id or name. |
| `cursor` | query | no | string |
| `limit` | query | no | integer, at least 1, at most 100 |

**Answers:**

- `200` A page of results. Data: [NodeBackup](/docs/api-reference/chapter-14#nodebackup) (a page of them).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `POST /admin/node-backups`

Back up a whole node.

Needs the admin:write ability and the backups.create permission.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `Idempotency-Key` | header | no | Any 1 to 255 printable characters. A retry with the same key and the same request gets the first answer again (with Idempotent-Replayed true) instead of running twice; kept 24 hours, per token. Only successful answers are kept. |

**Body:** [NodeBackupCreate](/docs/api-reference/chapter-14#nodebackupcreate)

**Answers:**

- `202` Accepted; the nodes apply the change next. Poll the operation in Location. Data: [NodeBackup](/docs/api-reference/chapter-14#nodebackup).
- `400` The Idempotency-Key header is not 1 to 255 printable characters (urn:lesta:problem:invalid-idempotency-key). A [problem document](/docs/api-reference/chapter-14#problem).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `409` The Idempotency-Key was used with another request (idempotency-key-reused), or that request is still running (idempotency-key-in-progress). A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `GET /admin/node-backups/{backup}`

One whole-node backup.

Needs the admin:read ability and the backups.view permission.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `backup` | path | yes | The backup's id. |

**Answers:**

- `200` The resource. Data: [NodeBackup](/docs/api-reference/chapter-14#nodebackup).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `DELETE /admin/node-backups/{backup}`

Delete a whole-node backup.

Needs the admin:write ability and the backups.delete permission.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `backup` | path | yes | The backup's id. |

**Answers:**

- `202` Accepted; the nodes apply the change next. Poll the operation in Location. Data: null.
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

---

## Chapter 13: Administration: every customer's resources

### `GET /admin/web-domains`

Every customer's web domains.

Needs the admin:read ability and the web_domains.view_any permission. Read only; each entry carries account_id.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `filter[account]` | query | no | The account's id. |
| `filter[domain]` | query | no | Exactly this domain. |
| `cursor` | query | no | string |
| `limit` | query | no | integer, at least 1, at most 100 |

**Answers:**

- `200` A page of results. Data: [WebDomain](/docs/api-reference/chapter-14#webdomain) (a page of them).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `GET /admin/dns-zones`

Every customer's dns zones.

Needs the admin:read ability and the dns_zones.view_any permission. Read only; each entry carries account_id.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `filter[account]` | query | no | The account's id. |
| `filter[domain]` | query | no | Exactly this domain. |
| `cursor` | query | no | string |
| `limit` | query | no | integer, at least 1, at most 100 |

**Answers:**

- `200` A page of results. Data: [DnsZone](/docs/api-reference/chapter-14#dnszone) (a page of them).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `GET /admin/mail-domains`

Every customer's mail domains.

Needs the admin:read ability and the mail_domains.view_any permission. Read only; each entry carries account_id.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `filter[account]` | query | no | The account's id. |
| `filter[domain]` | query | no | Exactly this domain. |
| `cursor` | query | no | string |
| `limit` | query | no | integer, at least 1, at most 100 |

**Answers:**

- `200` A page of results. Data: [MailDomain](/docs/api-reference/chapter-14#maildomain) (a page of them).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `GET /admin/databases`

Every customer's databases.

Needs the admin:read ability and the tenant_databases.view_any permission. Read only; each entry carries account_id.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `filter[account]` | query | no | The account's id. |
| `cursor` | query | no | string |
| `limit` | query | no | integer, at least 1, at most 100 |

**Answers:**

- `200` A page of results. Data: [Database](/docs/api-reference/chapter-14#database) (a page of them).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

### `GET /admin/cron-jobs`

Every customer's cron jobs.

Needs the admin:read ability and the cron_jobs.view_any permission. Read only; each entry carries account_id.

| Parameter | In | Required | Description |
| --- | --- | --- | --- |
| `filter[account]` | query | no | The account's id. |
| `cursor` | query | no | string |
| `limit` | query | no | integer, at least 1, at most 100 |

**Answers:**

- `200` A page of results. Data: [CronJob](/docs/api-reference/chapter-14#cronjob) (a page of them).
- `401` The token is missing, unknown, revoked or expired (urn:lesta:problem:invalid-token). A [problem document](/docs/api-reference/chapter-14#problem).
- `403` The token lacks the ability (missing-ability), is restricted to another account (account-restricted), the account is suspended and the call changes it (account-suspended), the login lacks the platform permission (missing-permission), or the user may not do this (about:blank). A [problem document](/docs/api-reference/chapter-14#problem).
- `404` No such resource here. A [problem document](/docs/api-reference/chapter-14#problem).
- `422` The input is invalid (urn:lesta:problem:validation, with errors), or a package limit is reached (urn:lesta:problem:quota-exceeded). A [problem document](/docs/api-reference/chapter-14#problem).
- `429` Too many requests (about:blank), or too many failed tokens from this address (too-many-failed-tokens). A [problem document](/docs/api-reference/chapter-14#problem).

---

## Chapter 14: Schemas

The objects the calls take and answer. A type with `null` may be null. `uuid` and `date-time` values are strings in those formats (timestamps in UTC).

### <a id="problem"></a>Problem

An RFC 9457 problem document. type is urn:lesta:problem:<name> for the problems the API names (invalid-token, too-many-failed-tokens, missing-ability, missing-permission, account-restricted, account-suspended, validation, quota-exceeded, no-capacity, invalid-idempotency-key, idempotency-key-reused, idempotency-key-in-progress), otherwise about:blank with the status's own title.

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `type` | string (uri) | yes |  |
| `title` | string | yes |  |
| `status` | integer | yes |  |
| `detail` | string | yes |  |
| `errors` | object | no | Only for urn:lesta:problem:validation, the field errors. |

### <a id="user"></a>User

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `id` | integer | yes |  |
| `name` | string | yes |  |
| `email` | string (email) | yes |  |
| `token` | object | yes |  |

### <a id="account"></a>Account

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `id` | string | yes | The account's public id, used in the account paths. |
| `name` | string | yes |  |
| `contact_email` | string or null | yes |  |
| `package` | null or object | yes |  |
| `is_owner` | boolean | yes |  |
| `reseller_account_id` | string or null | yes | The public id of the reseller account that manages this one. |
| `suspended` | boolean | yes |  |
| `suspended_at` | string or null (date-time) | no |  |
| `memberships_count` | integer | no | Only in the admin account calls. |
| `created_at` | string or null (date-time) | yes |  |

### <a id="operationref"></a>OperationRef

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `id` | string (uuid) | yes |  |
| `status` | string: `pending`, `applied`, `failed` | yes |  |
| `url` | string | yes |  |

### <a id="operation"></a>Operation

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `id` | string (uuid) | yes |  |
| `action` | string | yes | What was done, for example web_domain.created. |
| `status` | string: `pending`, `applied`, `failed` | yes | failed when any step failed, pending while any step is on its way, applied when all are done. |
| `steps` | list of object | yes |  |
| `created_at` | string or null (date-time) | yes |  |

### <a id="webdomaincreate"></a>WebDomainCreate

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `domain` | string | yes |  |
| `aliases` | list of string | no |  |
| `web_template` | string | no |  |
| `web_server` | string: `nginx`, `apache` | no |  |
| `php_version` | string or null | no |  |
| `ssl_mode` | string: `none`, `manual`, `lets_encrypt` | no |  |

### <a id="webdomainupdate"></a>WebDomainUpdate

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `domain` | string | no |  |
| `aliases` | list of string | no | Replaces all aliases. |
| `web_template` | string | no |  |
| `web_server` | string: `nginx`, `apache` | no |  |
| `php_version` | string or null | no |  |
| `ssl_mode` | string: `none`, `manual`, `lets_encrypt` | no |  |
| `waf_mode` | string: `off`, `detect`, `block` | no |  |
| `waf_preset` | string | no |  |
| `waf_excluded_rules` | list of integer | no |  |
| `hotlink_protection` | boolean | no |  |
| `hotlink_allowed_hosts` | list of string | no |  |

### <a id="dnszonecreate"></a>DnsZoneCreate

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `domain` | string | yes |  |
| `ttl` | integer, at least 60, at most 604800 | no |  |

### <a id="dnszoneupdate"></a>DnsZoneUpdate

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `domain` | string | no |  |
| `ttl` | integer, at least 60, at most 604800 | no |  |

### <a id="dnsrecordcreate"></a>DnsRecordCreate

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `name` | string | yes | The name inside the zone, for example www, or @ for the zone itself. |
| `type` | string: `A`, `AAAA`, `CNAME`, `MX`, `TXT`, `NS`, `SRV`, `CAA`, `PTR` | yes |  |
| `priority` | integer or null | no | Required for MX and SRV, ignored otherwise. |
| `value` | string | yes |  |

### <a id="dnsrecordupdate"></a>DnsRecordUpdate

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `name` | string | no |  |
| `type` | string: `A`, `AAAA`, `CNAME`, `MX`, `TXT`, `NS`, `SRV`, `CAA`, `PTR` | no |  |
| `priority` | integer or null | no |  |
| `value` | string | no |  |

### <a id="maildomaincreate"></a>MailDomainCreate

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `domain` | string | yes |  |
| `antivirus_enabled` | boolean | no |  |
| `antispam_enabled` | boolean | no |  |
| `dkim_enabled` | boolean | no |  |

### <a id="maildomainupdate"></a>MailDomainUpdate

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `antivirus_enabled` | boolean | no |  |
| `antispam_enabled` | boolean | no |  |
| `dkim_enabled` | boolean | no |  |
| `catchall_email` | string or null | no | A mailbox on this domain that gets mail for unknown addresses, or null for none. |

### <a id="mailboxcreate"></a>MailboxCreate

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `local_part` | string | yes |  |
| `quota_mb` | integer or null | no |  |
| `forward_to` | string or null | no |  |
| `forward_only` | boolean | no |  |
| `autoreply_enabled` | boolean | no |  |
| `autoreply_message` | string or null | no |  |
| `spam_sensitivity` | string: `off`, `low`, `medium`, `high` | no | How readily spam goes to the Junk folder (from a score of 8, 5 or 3; off never). Needs antispam on the mail domain. |
| `spam_allow` | list of string | no | Addresses or domains whose mail is never moved to Junk. |
| `spam_block` | list of string | no | Addresses or domains whose mail is deleted without delivery. |

### <a id="mailboxupdate"></a>MailboxUpdate

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `quota_mb` | integer or null | no |  |
| `forward_to` | string or null | no |  |
| `forward_only` | boolean | no |  |
| `autoreply_enabled` | boolean | no |  |
| `autoreply_message` | string or null | no |  |
| `spam_sensitivity` | string: `off`, `low`, `medium`, `high` | no | How readily spam goes to the Junk folder (from a score of 8, 5 or 3; off never). Needs antispam on the mail domain. |
| `spam_allow` | list of string | no | Addresses or domains whose mail is never moved to Junk. |
| `spam_block` | list of string | no | Addresses or domains whose mail is deleted without delivery. |

### <a id="mailboxwithpassword"></a>MailboxWithPassword

All the fields of [Mailbox](/docs/api-reference/chapter-14#mailbox), and:

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `password` | string or null | yes | Shown in this answer only. Null in a replayed answer. |

### <a id="databasecreate"></a>DatabaseCreate

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `label` | string, matching `^[a-z][a-z0-9_]{0,32}$` | yes |  |

### <a id="databasewithpassword"></a>DatabaseWithPassword

All the fields of [Database](/docs/api-reference/chapter-14#database), and:

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `password` | string or null | yes | Shown in this answer only. Null in a replayed answer. |

### <a id="databaseusercreate"></a>DatabaseUserCreate

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `label` | string, matching `^[a-z][a-z0-9]{0,15}$` | yes | Not ro, and unique on the database. |
| `access` | string: `full`, `read_only` | yes | full is what the database's own user may do; read_only is SELECT and SHOW VIEW. |

### <a id="databaseuserupdate"></a>DatabaseUserUpdate

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `access` | string: `full`, `read_only` | yes |  |

### <a id="databaseuserwithpassword"></a>DatabaseUserWithPassword

All the fields of [DatabaseUser](/docs/api-reference/chapter-14#databaseuser), and:

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `password` | string or null | yes | Shown in this answer only. Null in a replayed answer. |

### <a id="cronjobcreate"></a>CronJobCreate

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `minute` | string | no |  |
| `hour` | string | no |  |
| `day_of_month` | string | no |  |
| `month` | string | no |  |
| `day_of_week` | string | no |  |
| `command` | string, at most 1024 characters | yes |  |

### <a id="cronjobupdate"></a>CronJobUpdate

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `minute` | string | no |  |
| `hour` | string | no |  |
| `day_of_month` | string | no |  |
| `month` | string | no |  |
| `day_of_week` | string | no |  |
| `command` | string, at most 1024 characters | no |  |

### <a id="backupcreate"></a>BackupCreate

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `parts` | list of string: `files`, `databases`, `mail`, at least 1 item | yes |  |
| `node` | string | no | The node's name, as backups show it. May be left out when the account has data on one node only. |
| `label` | string or null, at most 100 characters | no |  |

### <a id="backuprestore"></a>BackupRestore

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `parts` | list of string: `files`, `databases`, `mail`, at least 1 item | yes |  |
| `confirm` | boolean: `true` | yes | The restore overwrites the current data of these parts. |

### <a id="adminaccountcreate"></a>AdminAccountCreate

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `name` | string | yes |  |
| `contact_email` | string or null | no | The owner's e-mail when left out. |
| `package` | string (uuid) | yes | An active package's id. |
| `owner_name` | string | yes | Used only when the owner has no login yet. |
| `owner_email` | string | yes |  |

### <a id="adminaccountupdate"></a>AdminAccountUpdate

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `name` | string | no |  |
| `contact_email` | string or null | no |  |
| `package` | string (uuid) | no |  |

### <a id="adminaccountdelete"></a>AdminAccountDelete

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `confirm` | string | yes | The account's id, to confirm. |

### <a id="adminassignreseller"></a>AdminAssignReseller

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `reseller_account_id` | string | yes |  |

### <a id="package"></a>Package

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `id` | string (uuid) | yes |  |
| `name` | string | yes |  |
| `description` | string or null | yes |  |
| `is_active` | boolean | yes |  |
| `limits` | object | yes | One entry per resource type. configured false means none may be created; limit null means unlimited. |
| `accounts_count` | integer | no |  |
| `created_at` | string or null (date-time) | yes |  |

### <a id="packagecreate"></a>PackageCreate

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `name` | string | yes |  |
| `description` | string or null | no |  |
| `is_active` | boolean | no | false when left out, as in the panel. |

### <a id="packageupdate"></a>PackageUpdate

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `name` | string | no |  |
| `description` | string or null | no |  |
| `is_active` | boolean | no |  |

### <a id="packagelimit"></a>PackageLimit

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `limit_value` | integer or null, at least 0 | no | null for unlimited. |

### <a id="node"></a>Node

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `id` | string (uuid) | yes |  |
| `name` | string | yes |  |
| `hostname` | string | yes |  |
| `mail_hostname` | string or null | no |  |
| `enrollment_status` | string | yes |  |
| `agent_version` | string or null | no |  |
| `agent_reachable` | boolean | yes |  |
| `last_seen_at` | string or null (date-time) | no |  |
| `ping` | object | yes |  |
| `services` | list of object | yes |  |
| `capabilities` | list of object | no |  |
| `backups_scheduled` | boolean | yes |  |
| `suspended` | boolean | yes |  |
| `suspended_at` | string or null (date-time) | no |  |
| `suspension_source` | string or null | no |  |
| `created_at` | string or null (date-time) | yes |  |

### <a id="nodebackup"></a>NodeBackup

A whole-node backup. Never its encryption key.

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `id` | string (uuid) | yes |  |
| `label` | string or null | yes |  |
| `node` | object | yes |  |
| `status` | string: `running`, `ready`, `failed` | yes |  |
| `error` | string or null | yes |  |
| `included_capabilities` | list of string | yes |  |
| `size_bytes` | integer or null | yes |  |
| `completed_at` | string or null (date-time) | yes |  |
| `created_at` | string or null (date-time) | yes |  |

### <a id="nodebackupcreate"></a>NodeBackupCreate

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `node` | string (uuid) | yes |  |
| `label` | string or null | no |  |

### <a id="suspension"></a>Suspension

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `suspended` | boolean | yes |  |
| `suspended_at` | string or null (date-time) | yes |  |
| `suspension_source` | string or null: `manual`, `cascade`, null | yes | cascade when the account was suspended, manual when this resource itself was. |

### <a id="provisioning"></a>Provisioning

The latest change sent to the node. Null when nothing was ever sent.

One of: null, object.

### <a id="webdomain"></a>WebDomain

All the fields of [Suspension](/docs/api-reference/chapter-14#suspension), and:

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `id` | string (uuid) | yes |  |
| `account_id` | string | no | Only in the admin lists. |
| `domain` | string | yes |  |
| `aliases` | list of string | yes |  |
| `web_template` | string | yes |  |
| `web_server` | string | yes |  |
| `php_version` | string or null | yes |  |
| `ssl_mode` | string | yes |  |
| `certificate` | object | yes |  |
| `waf_mode` | string | yes |  |
| `waf_preset` | string | yes |  |
| `waf_excluded_rules` | list of integer | yes |  |
| `hotlink_protection` | boolean | yes |  |
| `hotlink_allowed_hosts` | list of string | yes |  |
| `provisioning` | [Provisioning](/docs/api-reference/chapter-14#provisioning) | yes |  |
| `created_at` | string or null (date-time) | yes |  |

### <a id="dnszone"></a>DnsZone

All the fields of [Suspension](/docs/api-reference/chapter-14#suspension), and:

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `id` | string (uuid) | yes |  |
| `account_id` | string | no | Only in the admin lists. |
| `domain` | string | yes |  |
| `ttl` | integer | yes |  |
| `records_count` | integer | yes |  |
| `provisioning` | [Provisioning](/docs/api-reference/chapter-14#provisioning) | yes |  |
| `created_at` | string or null (date-time) | yes |  |

### <a id="dnsrecord"></a>DnsRecord

All the fields of [Suspension](/docs/api-reference/chapter-14#suspension), and:

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `id` | string (uuid) | yes |  |
| `name` | string | yes |  |
| `type` | string | yes |  |
| `priority` | integer or null | yes |  |
| `value` | string | yes |  |
| `created_at` | string or null (date-time) | yes |  |

### <a id="maildomain"></a>MailDomain

All the fields of [Suspension](/docs/api-reference/chapter-14#suspension), and:

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `id` | string (uuid) | yes |  |
| `account_id` | string | no | Only in the admin lists. |
| `domain` | string | yes |  |
| `antivirus_enabled` | boolean | yes |  |
| `antispam_enabled` | boolean | yes |  |
| `dkim_enabled` | boolean | yes |  |
| `dkim_selector` | string | yes |  |
| `catchall_email` | string or null | yes |  |
| `accounts_count` | integer | yes | How many mailboxes the domain has. |
| `provisioning` | [Provisioning](/docs/api-reference/chapter-14#provisioning) | yes |  |
| `created_at` | string or null (date-time) | yes |  |

### <a id="mailbox"></a>Mailbox

A mailbox. Never its password.

All the fields of [Suspension](/docs/api-reference/chapter-14#suspension), and:

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `id` | string (uuid) | yes |  |
| `local_part` | string | yes |  |
| `address` | string | yes |  |
| `quota_mb` | integer or null | yes |  |
| `forward_to` | string or null | yes |  |
| `forward_only` | boolean | yes |  |
| `autoreply_enabled` | boolean | yes |  |
| `autoreply_message` | string or null | yes |  |
| `spam_sensitivity` | string: `off`, `low`, `medium`, `high` | no | How readily spam goes to the Junk folder (from a score of 8, 5 or 3; off never). Needs antispam on the mail domain. |
| `spam_allow` | list of string | no | Addresses or domains whose mail is never moved to Junk. |
| `spam_block` | list of string | no | Addresses or domains whose mail is deleted without delivery. |
| `created_at` | string or null (date-time) | yes |  |

### <a id="database"></a>Database

A customer database. Never its password.

All the fields of [Suspension](/docs/api-reference/chapter-14#suspension), and:

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `id` | string (uuid) | yes |  |
| `account_id` | string | no | Only in the admin lists. |
| `label` | string | yes |  |
| `database_name` | string | yes |  |
| `database_user` | string | yes |  |
| `provisioning` | [Provisioning](/docs/api-reference/chapter-14#provisioning) | yes |  |
| `created_at` | string or null (date-time) | yes |  |

### <a id="databaseuser"></a>DatabaseUser

An extra user on a database. Never its password.

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `id` | string (uuid) | yes |  |
| `label` | string | yes |  |
| `username` | string | yes |  |
| `access` | string: `full`, `read_only` | yes |  |
| `created_at` | string or null (date-time) | yes |  |

### <a id="cronjob"></a>CronJob

All the fields of [Suspension](/docs/api-reference/chapter-14#suspension), and:

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `id` | string (uuid) | yes |  |
| `account_id` | string | no | Only in the admin lists. |
| `minute` | string | yes |  |
| `hour` | string | yes |  |
| `day_of_month` | string | yes |  |
| `month` | string | yes |  |
| `day_of_week` | string | yes |  |
| `command` | string | yes |  |
| `provisioning` | [Provisioning](/docs/api-reference/chapter-14#provisioning) | yes |  |
| `executions` | list of object | no | Only when one cron job is fetched, the last 20 runs, newest first. |
| `created_at` | string or null (date-time) | yes |  |

### <a id="backup"></a>Backup

An account backup. Never its encryption key.

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `id` | string (uuid) | yes |  |
| `label` | string or null | yes |  |
| `kind` | string: `manual`, `scheduled`, `before_restore`, `imported` | yes |  |
| `node` | string | yes | The name of the node the backup was taken on. |
| `status` | string: `running`, `ready`, `failed` | yes |  |
| `error` | string or null | yes |  |
| `parts` | list of string | yes |  |
| `size_bytes` | integer or null | yes |  |
| `skipped` | array | yes | What the backup could not include, and why. |
| `remote` | null or object | yes | The copy to the account's own storage. Null when none was made. |
| `last_restore` | null or object | yes | The latest restore from this backup. Null when it was never restored. |
| `completed_at` | string or null (date-time) | yes |  |
| `created_at` | string or null (date-time) | yes |  |

### <a id="usageresource"></a>UsageResource

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `type` | string: `web_domain`, `mail_account`, `tenant_database`, `unknown` | yes |  |
| `label` | string | yes |  |

### <a id="usagesnapshot"></a>UsageSnapshot

One collection cycle for one resource. Disk is the size at that moment; requests and bytes sent are what happened since the previous cycle.

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `id` | string (uuid) | yes |  |
| `resource` | [UsageResource](/docs/api-reference/chapter-14#usageresource) | yes |  |
| `disk_bytes` | integer or null | yes |  |
| `request_count` | integer or null | yes |  |
| `bytes_sent` | integer or null | yes |  |
| `collected_at` | string (date-time) | yes |  |

### <a id="usagemonth"></a>UsageMonth

One month for one resource.

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `id` | string (uuid) | yes |  |
| `resource` | [UsageResource](/docs/api-reference/chapter-14#usageresource) | yes |  |
| `period` | string | yes | The month, as YYYY-MM. |
| `disk_bytes_last` | integer or null | yes |  |
| `request_count_sum` | integer or null | yes |  |
| `bytes_sent_sum` | integer or null | yes |  |

### <a id="cursorlinks"></a>CursorLinks

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `first` | string or null | no |  |
| `last` | string or null | no |  |
| `prev` | string or null | no |  |
| `next` | string or null | no |  |

### <a id="cursormeta"></a>CursorMeta

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `path` | string | no |  |
| `per_page` | integer | no |  |
| `next_cursor` | string or null | no |  |
| `prev_cursor` | string or null | no |  |
