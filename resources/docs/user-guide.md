# LESta User Guide

This guide is for a hosting customer — someone with a real account on LESta, either as its **owner** or as a **member**.

**Note before anything else:** you cannot sign yourself up. There is no public registration — an administrator (or, later, a reseller) creates your account and login for you and emails you a link to set your own password the first time. If you don't have a login yet, ask your administrator.

---

## Chapter 1: Getting started

- **Log in** at the site's login page with the email and password your administrator gave you (or the password you set via the emailed link).
- **Dashboard** (the page you land on after logging in) shows a quick overview of your account: how many web domains, DNS zones, mail domains, mailboxes, databases, and cron jobs you have, each linking straight to that section. If your account is suspended, that's shown here too.
- If you belong to more than one account, **My account** in the sidebar (`/accounts/mine`) lists all of them; each row links to that account's own page. The dashboard itself only ever shows your *first* account if you have several — there is no account switcher yet, so if you manage more than one, use "My account" to get to the others.

---

## Chapter 2: Your account

Click through from "My account," or from the dashboard, to reach your account's own page.

- **View**: name, package, suspension status, resource counts, and the list of people (members) on the account.
- **Update** name, contact email, or package — available to the account **owner** only. A **member** sees a plain, read-only summary instead of the edit form, so there's nothing to click that wouldn't work.
- **Suspend / Unsuspend your own account**, and **Delete it** — also owner-only. Suspending cascades to every web domain, DNS zone, mail domain, tenant database, and cron job you have; deleting removes everything permanently.
- **Invite or remove a member**: an owner can add another person to the account (name, email, and role — member or owner) and remove one, right from this same page. A brand-new email gets its own login and a password-reset email, the same way your own login was first created.

**Note:** if your account is managed by a reseller, you won't see that relationship reflected here in any special way — reseller access is invisible from the account's own page; it just means the reseller's own users can manage it too, the same as if they were a direct owner.

---

## Chapter 3: Web domains

`Domains` in the sidebar (`/domains`).

- **Add domain**: the domain name, optional **aliases** (one per line — extra hostnames that serve the same site), a **template**, **web server** (nginx or Apache — Apache only works if your node supports it), and **SSL mode** (None, Manual, or Let's Encrypt).
- **Edit**: change the same fields after creation.
- **Suspend / Unsuspend**: takes the site offline/back online without deleting it.
- **Delete**: removes it permanently.

---

## Chapter 4: DNS

`DNS` in the sidebar (`/dns`). DNS is organized as **zones** (one per domain) containing **records**.

- **Add zone**: domain name and TTL (how long resolvers should cache answers, in seconds).
- Inside a zone's edit page, **Add record**: name (e.g. `www`, or `@` for the domain itself), type (A, AAAA, NS, CNAME, MX, TXT, SRV, PTR, or CAA — a priority field appears for MX/SRV), and value. Records can be individually edited, suspended/unsuspended, or deleted without touching the rest of the zone.
- The zone itself can also be suspended/unsuspended (stops/resumes DNS answering for the whole domain) or deleted (removes it and every record in it).

---

## Chapter 5: Mail

`Mail` in the sidebar (`/mail`). Mail is organized as **domains** containing **mailboxes**, the same shape as DNS.

- **Add domain**: the domain name, a **catch-all email** (optional — where mail to unrecognized addresses at this domain goes), and three toggles: scan incoming mail for viruses, scan for spam (both on by default), and sign outgoing mail with DKIM (off by default — turning it on starts real key generation, and the key rotates automatically on a schedule you don't need to manage).
- Inside a domain's edit page, **Add mailbox**: a name (the part before the `@`), optional quota in MB, optional forwarding address, a "forward only, don't keep a copy" toggle, and an autoreply toggle with its own message.
  - **Read this before adding a mailbox:** the mailbox's password is generated automatically and shown to you **exactly once**, in a dialog, right after you create it (or after you rotate it). It cannot be recovered afterward — if you close the dialog without copying it, use **Rotate password** on that mailbox to get a new one.
- Each mailbox can be edited (quota, forwarding, autoreply — not its name), have its password rotated, suspended/unsuspended, or deleted, all from the same page.
- The domain itself can be suspended/unsuspended (stops/resumes all mail for every mailbox on it) or deleted (removes it and every mailbox in it).

---

## Chapter 6: Databases

`Databases` in the sidebar (`/tenant-databases`).

- **Add database**: just a label (lowercase letters, digits, and underscores, starting with a letter — this is used to build the real database name and **cannot be changed later**). This creates a real MariaDB database plus a matching database user.
- The database's password is shown once, the same one-time-reveal rule as mailbox passwords (Chapter 5) — copy it immediately, or use **Rotate password** later if you missed it.
- Suspend/unsuspend (blocks/restores the database user's own access) or delete (removes the database and its user permanently) from the same page.

---

## Chapter 7: Cron jobs

`Cron jobs` in the sidebar (`/cron-jobs`).

- **Add cron job**: the five standard cron fields (minute, hour, day of month, month, day of week — `*` means "every"), and the command to run.
- **Note:** your job runs as a shared, non-root system user on the node, alongside other accounts' jobs on the same node — it is not isolated in its own sandbox from them.
- Edit, suspend/unsuspend, or delete a job from the same list.

---

## Chapter 8: Usage & statistics

`Usage` in the sidebar (`/usage`). Shows your own resource usage: raw daily snapshots for the last 90 days (per web domain, mailbox, and database), plus monthly rollups kept indefinitely once the raw data ages out.

If your account hasn't been fully set up yet, this page (like Domains, DNS, Mail, Databases, and Cron jobs) simply says there's no hosting account yet, rather than showing an error — ask your administrator to finish setting things up.

---

## Chapter 9: Personal settings

Separate from your hosting account — these control your own login, not anything you host.

- **Profile** (`/settings/profile`): your name and email, and a way to delete your own login entirely (not your hosting account — ask your administrator if you actually want the account gone, see Chapter 2).
- **Security** (`/settings/security`): change your password, turn on two-factor authentication (with recovery codes), and register passkeys.
- **Appearance**: light/dark/system theme.

---

## Chapter 10: If a LESta administrator is helping you

If you contact support about a problem with your account, an administrator may briefly sign in as you to see exactly what you're seeing, with your permission, to diagnose it faster. When they do, a clearly labeled banner appears at the top of every page for the whole time they're doing this, naming who is doing it, with a button to end it immediately. This is always logged.

---

## Chapter 11: Summary of gaps and notes

1. **No account switcher** — the dashboard only ever reflects your first account if you belong to several; use "My account" to reach the others.
2. Mailbox and database passwords are genuinely one-time — there's no "show password again" anywhere, only "rotate" (which invalidates the old one immediately).
3. No self-service sign-up, ever, by design — only an administrator (or later, a reseller) can create your account.
