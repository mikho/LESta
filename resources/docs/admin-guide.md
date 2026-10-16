# LESta Admin Guide

This is a working manual for a platform administrator (`provider_admin`, or a custom platform-scope role — see Chapter 1) of LESta, the hosting control panel.

---

## Chapter 1: Roles overview

LESta has no single "admin" flag. Access is built from a few independent pieces that combine:

- **Platform permissions** (`provider_admin` role, or a custom platform-scope role with specific permissions — see below). A user with the full permission catalog can manage every account, node, backup, package, role, and cross-account usage view. This is the role this guide assumes you have.
- **Node-admin delegation.** A user with no platform role at all can still be granted full admin of one specific node (infrastructure only — they never gain access to the accounts/domains/mail/databases hosted on it). See Chapter 6.
- **Account membership** (`owner` or `member`, scoped to one account). This is the tenant/customer side, covered in the separate **User Guide**, not this document.
- **Reseller.** An account can be marked as managing other accounts. A user with an `owner` membership on the reseller account automatically gets the same access to every account it manages, with no separate grant needed. See Chapter 4.

**Custom platform-scope roles**: `Roles` in the sidebar (`/roles`) lets you create a role narrower than "everything" — a name, description, and a checkbox per permission from the full catalog (`App\Models\Permission::CATALOG`). This is separate from `provider_admin` itself, which stays the fixed, always-everything foundational role and is never editable or deletable through this screen. Assign a custom role to a user the same way any platform-scope membership is assigned (a membership with no `account_id`).

---

## Chapter 2: Logging in and your own settings

- **Log in** at `/login` with email and password. There is no public self-registration — only an administrator can create a new login (Chapter 3), and there is no "forgot my account exists" recovery path other than someone with database access checking directly.
- **Forgot password**: the "Forgot your password?" link on the login page sends a reset email (real password-reset flow, not a stub).
- **Two-factor authentication and passkeys**: `Settings → Security` (`/settings/security`) lets you enable 2FA (with recovery codes) and register passkeys, independent of your role.
- **Profile**: `Settings → Profile` (`/settings/profile`) — name, email, and account deletion (deletes your own login, not any hosting account).
- **Appearance**: `Settings → Appearance` — light/dark/system theme, cosmetic only.
- **API tokens**: `Settings → API tokens`: tokens for your own scripts. As a platform user you can also give a token the **Platform administration** abilities. See the **API Guide**.

### The dashboard

As a platform administrator your dashboard shows **Node status** for every node (a node admin sees only their own nodes): whether the node's agent is **online** (it reported in the last three minutes), the **ping** time from the panel to the node (checked every minute), and every service the node runs, with failed or stopped ones in red. It refreshes every 30 seconds. The node's name links to its page (Chapter 5).

---

## Chapter 3: Managing hosting accounts

Accounts are the top-level "customer" object — every domain, mailbox, database, and cron job belongs to exactly one account.

### Viewing accounts

`Accounts` in the sidebar (`/accounts`) shows every account on the platform, searchable by name or contact email. Opening one (`/accounts/{account}`) shows its package, suspension status, resource counts, and its members. Opening an account this way is logged as a support view (a real, separate audit trail entry from a tenant just looking at their own account) — unless the account is genuinely your own.

If an account has no hosting set up at all yet, its tenant-facing pages (Domains, DNS, Mail, Databases, Cron jobs, Usage, and the dashboard) show a plain "no hosting account" message to whoever's logged in as it, instead of an error page.

### Creating an account

`Accounts → Create account` (`/accounts/create`). You provide:

- **Account name**
- **Contact email** (optional)
- **Package** (a dropdown of active packages — see Chapter 8 if this list looks wrong)
- **Owner name** and **owner email**

If the owner email already belongs to an existing user, that person is simply added as the account's owner. If not, a brand-new login is created for them with an unusable random password, and a real password-reset email is sent so they can set their own password the first time they use it — the same mechanism used to create your own admin login. You never see or set anyone's password directly.

**Gap (disclosed, not yet built):** there is no way for a reseller to create a new account themselves yet, only a platform admin can. The API cannot create accounts yet either (it is being built in steps, see the API Guide).

### Editing / suspending / deleting an account

From `/accounts/{account}`:

- **Update** name, contact email, or package.
- **Suspend / Unsuspend** — cascades to every web domain, DNS zone, mail domain, tenant database, and cron job the account owns (they get suspended/unsuspended too, tagged as "cascade" so you can tell them apart from something a tenant suspended manually).
- **Delete**: deletes the account and everything it owns. Cannot be undone. On the servers this also removes the account's website files, its SFTP key and every backup of the account (copies the customer sent to their own storage are not touched).

None of these require the account to have zero resources first; delete cascades through everything.

**The "LESta platform" account** holds each node's own tools domain (webmail and Adminer on the node's hostname). It is internal: it is not listed among the accounts or in the cross-account lists, and its package is not listed and cannot be edited or deleted.

### Members

Still on `/accounts/{account}`, under **Members**: invite a new person (name, email, role — member or owner) or remove an existing one. This is the same screen and same ability an account owner has for their own account (see the User Guide) — an admin with the `memberships.create`/`memberships.delete` permission can do it for any account, not just their own.

### Supporting a customer directly (impersonation)

Next to each member, with the `memberships.impersonate` permission: **Impersonate**. This asks for a short reason, then signs you in as that person for real — not the read-only support view above, an actual session swap, so you see exactly what they see and can act as them. A persistent banner appears on every page for as long as this is active, naming who you're really are, with a **Return to admin** button to end it. Starting and stopping is always recorded in the audit log. You can't impersonate another admin's platform-level login this way — only a real account membership is a valid target.

---

## Chapter 4: Reseller management

A reseller is not a separate object — it's an ordinary account that other accounts point at. Any user with an `owner` membership on the reseller account automatically gets full access to every account it manages, exactly as if they were a direct owner (no separate setup per managed account).

From an account's own page (`/accounts/{account}`), under **Reseller**:

- **Assign**: start typing the target account's name or ID and pick it from the suggestions to make _this_ account manage that one. Nesting is one level only — a reseller-managed account cannot itself become a reseller, and an existing reseller cannot become someone else's managed account.
- **Unassign**: removes the relationship.

**Gap (disclosed, deliberately deferred):** a reseller cannot create new accounts themselves yet — only assign existing ones. This was an explicit scoping decision, not an oversight, but it means "reseller" today only means "manages accounts an admin already created and handed over."

---

## Chapter 5: Managing infrastructure nodes

A node is one physical/virtual server running the LESta agent. `Nodes` in the sidebar (`/nodes`).

### Creating a node

`Nodes → Add node`: **Name** and **Hostname**. This just registers the node in the database — it does not install or configure anything on the actual server.

### Enrolling the real server

On a node's own page (`/nodes/{node}/edit`), under **Enrollment**: **Issue enrollment token** generates a one-time token, shown once in a dialog. This token is what you hand to the real server's own installer script (`.install/services/.../install.sh`) so the agent can authenticate itself back to LESta. The page also shows enrollment status, last-seen time, and the agent/protocol version once it has actually checked in. If the agent hasn't been heard from recently, this page shows a clear warning, since every capability status below becomes untrustworthy the moment the agent goes quiet.

### Declaring capabilities

Still on the node's edit page, under **Capabilities**: a dropdown lets you tell LESta "this node can now do X" after you've run the real installer for that service on the actual machine (this is a manual declaration, nothing here runs an installer for you). The admin-declarable capabilities:

- `web.nginx.v1`, `web.apache.v1`, `web.php-fpm.v1`
- `dns.bind9.v1`
- `tls.acme.v1`
- `database.tenant.v1`
- `scheduler.account-cron.v1`
- `mail.smtp-imap.v1`, `mail.webmail.v1`
- `backup.encrypted-artifacts.v1`
- `metrics.usage.v1`
- `files.manager.v1`, `tools.adminer.v1`

Two capability-shaped strings deliberately don't appear here: `system.account-identity.v1` (dispatched automatically the first time an account needs its own system user on a node, nothing for an admin to declare) and `database.control-plane.v1` (a fixed internal key for this application's own database, never a real per-node capability).

**Status, not just a suspend toggle.** Each capability shows a real status, confirmed by the node's own agent, never just an admin's say-so:

- **Not installed** — declared, but the agent has never confirmed it's actually running.
- **Running**: the agent's heartbeat reported it installed **and every service behind it running** (for example nginx; exim4 and dovecot for mail). Only the agent's heartbeat can ever set this.
- **Stopped**: the agent reported it, but a service behind it is down, or a later heartbeat no longer reports it. You can also set this by hand, or reset a capability back to **Not installed**. These two buttons only change what the panel records; they do nothing on the server.
- **Suspended**: an admin's own suspend action, shown regardless of what the agent reports underneath; unsuspending reveals whatever the real status underneath actually is. Suspending is a hold inside the panel; it does not stop anything on the server.
- **Unknown** — the node's agent hasn't been reachable recently, so any `running`/`stopped` value on file can't be trusted right now. This overrides everything except an active suspension.

`metrics.usage.v1` is the one exception: it has no standalone install step of its own (it's built into the web server and database installers), so it's simply labeled "Not tracked" rather than carrying a status.

### Services: start, stop, restart

Under **Services** the node page lists the services the node last reported, each with its state. Unused PHP versions are left out. For the customer-facing services, nginx, Apache, BIND (`named`), each PHP-FPM version in use, the tenant database (`mariadb@tenant`), cron, Exim and Dovecot, you get **Start** (when it's down), **Restart**, and **Stop** (when it's up). Stop asks first and says what on the node depends on the service, for example "2 web domains using PHP 8.3".

The node's own LESta services (its appliers and firewall), `ssh` and the panel's own database are shown but cannot be controlled from here: stopping them would cut the panel off from the node, with no way to start them again from the panel.

The node carries the action out a moment later; the row shows "stop requested" until it has, then the new state. Every action is recorded in the audit log. Provider admins with `nodes.update` and the node's own admins can do this.

### Installed services: uninstall

Under **Installed services**, each LESta service on the node (nginx, Apache, BIND, MariaDB, cron, mail, backups, PHP-FPM, Adminer, webmail) has an **Uninstall** button. Removing one stops it, purges its packages and removes its firewall ports and configuration on the node. For MariaDB, mail, backups and webmail the data is moved aside on the node (to `<data directory>-removed-<time>`), never deleted, so it can be recovered by hand. Confirm by typing the node's name.

Uninstall is refused, with the reason shown, while:

- customer resources on the node use the service ("In use by 3 web domains", "In use by 2 databases", backups for the backups service), or
- another installed service needs it (mail needs nginx and BIND; Adminer needs PHP-FPM and nginx; webmail needs mail, PHP-FPM and nginx). The node checks this again itself.

Only one removal runs on a node at a time. When the node reports success, the panel removes the service's capabilities from the node by itself. A failed removal shows the node's error on the row.

### Node admin delegation

Further down the same page, **Node admins** (only visible if you can manage this): grant a user (by email) full admin of this one node — they can manage it, its capabilities, and suspend it, but never reach any tenant's domains/mail/databases hosted on it. Only a platform admin can grant or revoke this; a delegated node admin can never grant further node admins themselves.

### Orphaned account identities

Each tenant account gets its own dedicated system user on a node the first time it needs one there (a web domain or a cron job). If everything the account had on that node is later removed, the system user is left behind (not automatically cleaned up), this section lists those and lets you delete them. Deleting one here keeps the account's files on the node; deleting the whole account removes them (Chapter 3).

### Suspending, scheduled backups, deleting

- **Suspend / Unsuspend** the node — cascades to its active capabilities.
- **Scheduled backups**: a toggle that, when on, dispatches a new backup for this node every day. Requires the node to actually have the backup capability declared (above).
- **Delete**: blocked if the node still has any dependent resources (domains, zones, databases, cron jobs, or provisioning history).

---

## Chapter 6: Backups

`Backups` in the sidebar (`/backups`) — platform-admin only, never visible to a tenant. A backup is a real, encrypted, whole-node snapshot (every account hosted on that node, together), not per-account.

- **New backup**: pick a node with an active backup capability, optionally label it. If the dropdown says "No node has an active backup.encrypted-artifacts.v1 capability yet," go declare that capability on a node first (Chapter 5).
- **Prepare download / Download**: backups are stored encrypted; "Prepare download" decrypts a single-use copy that expires a short time after you request it, then "Download" fetches that copy once.
- **Restore**: restores mail content and database tables to their state at backup time (overwriting anything since), then automatically re-syncs everything else on the node (web, DNS, cron, mail settings) from what's currently in the database. This is destructive for anything that changed after the backup — there's a confirmation dialog explaining that.
- **Delete**: removes the encrypted artifact from disk permanently.
- A node can also be set to create these automatically every day (Chapter 5, "Scheduled backups").

---

## Chapter 7: Usage & statistics

`Usage` in the sidebar (`/usage`). As a platform admin, appending `?account=<id>` lets you view any account's own usage (linked directly from that account's own admin page) — the same page a tenant sees for themselves, just for someone else's account. Shows raw daily snapshots (last 90 days) and monthly rollups (kept indefinitely) per resource: web domains, mail accounts, tenant databases.

---

## Chapter 8: Packages and quotas

A package defines what an account is allowed to have — one limit per resource type (web domains, DNS zones, DNS records, mail domains, mailboxes, databases, cron jobs). A `null`/empty limit means unlimited; **no quota set at all for a resource type means that resource is completely blocked** for any account on that package (not unlimited — the opposite).

- **Packages → Create package**: name, description, and an active/inactive toggle (inactive packages don't appear in the account-creation dropdown, but existing accounts on one are unaffected).
- On a package's own edit page: update its name/description/active status, and a **Quotas** table — one row per resource type, showing whether it's currently **Blocked (not configured)**, **Unlimited**, or a specific number, with a number field to set a new value per row (leave it empty and save to make that resource unlimited).
- **Delete**: only possible while no account is actually on that package.

The quota table only offers the seven resource types a real `Create*` action in the codebase actually enforces a limit against. `memberships` is **not** one of them — nothing that creates a membership checks a quota, so a `memberships` quota field would silently do nothing if offered.

The default "Starter" package on a fresh install has **no quota rows configured**, meaning every resource type starts out blocked for it — worth fixing right after a fresh install, before creating any real accounts on it.

---

## Chapter 9: Rotating the application key and database password

The panel's `.env` holds `APP_KEY` (which decrypts every stored secret: backup keys, storage keys, database and mail passwords) and the database password. If anyone who should not have could have read that file, rotate both. Everyone is signed out by a key rotation.

**APP_KEY**, in the panel's directory:

1. Back up `.env`.
2. If `PASSKEYS_USER_HANDLE_SECRET` is not set, set it to the current `APP_KEY` value: passkey sign-in is derived from it, and a new key would silently stop every passkey from working. (If nobody has registered a passkey yet, any new random value will do.)
3. Set `APP_PREVIOUS_KEYS` to the current key and put a new key in `APP_KEY` (`php artisan key:generate --show` prints one without writing it).
4. `php artisan config:clear`, restart the queue worker, reload the panel's php-fpm.
5. `php artisan lesta:reencrypt-secrets`. It re-encrypts every stored secret with the new key, reports what it did per table, and must end without errors. It refuses to run while step 2 is missing.
6. Remove `APP_PREVIOUS_KEYS`, then clear the config and restart the worker and php-fpm again.

**The database password** (the panel's own database user, `lesta_app`): set a new one with `ALTER USER 'lesta_app'@'127.0.0.1' IDENTIFIED BY '<new>'` as root on the control-plane database, put it in `DB_PASSWORD` in `.env` and in `/etc/lesta/mariadb-control-plane-app.env`, then clear the config and restart the worker and php-fpm.

---

## Chapter 10: Running list of gaps

1. There is no picker for which capabilities `install-selected.sh` has already detected versus what you still need to declare by hand, declaring a capability in the admin app is always a separate, manual step from actually installing it on the server (see the Installation Guide). Installing a service from the panel is not possible; uninstalling is (Chapter 5).
2. Resellers cannot create new accounts themselves (by design, deferred).
3. The API so far has tokens and two read endpoints; the rest is being built in steps (API Guide, Chapter 6).
4. If you ever need an admin with narrower platform permissions than "everything" but don't want to build a custom role by hand through the UI, there's no bulk/preset option — every custom role starts from an empty permission set.
