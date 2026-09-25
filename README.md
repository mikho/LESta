# LESta

LESta is a self-hosted web hosting control panel: a Laravel/Inertia/React control-plane application paired with a Go agent that runs on each managed infrastructure node (web, DNS, mail, database, and cron hosting).

## Documentation

Full guides — for hosting customers, platform administrators, and setting up infrastructure nodes — are built into the running application itself at `/docs`, sourced from `resources/docs/*.md`. The Installation Guide below is the one exception worth having outside the app too, since it's needed before the app is reachable from a fresh node.

## Setting up the control-plane application

This is the actual LESta web app (the admin/tenant control panel) — a standard Laravel 13 + Inertia v3 + React application, backed by MariaDB. It needs the full repository (not the sparse `.install`-only checkout `install.sh` below produces), since it's the application itself.

`install-cp.sh`, at the repository root, guides you through it: `.env` setup, `composer`/`npm` dependencies, a production asset build, and database migrations. It never touches your web server, systemd, or crontab configuration — it prints copy-pasteable guidance for those at the end instead, since they depend on your own setup.

```sh
git clone https://github.com/mikho/LESta.git
cd LESta
./install-cp.sh
```

Answer its prompts (application URL, database connection — or run this on the same node as `.install/services/mariadb/install.sh`, in the ADR's own default single-node deployment, and it picks up that installer's real generated credentials automatically instead of asking). Pass `--yes` to accept every default non-interactively (a repeat/scripted install), and `--seed`/`--no-seed` to control seeding directly instead of being asked. See `install-cp.sh --help` for details.

Once it finishes and you've wired up the web server / queue worker / scheduler it prints instructions for, log in and continue with the in-app **Admin Guide** (`/docs/admin-guide`) to create your first hosting account, or the **Installation Guide** (`/docs/installation-guide`) to enroll your first node.

## Setting up a hosting node

A node is a separate Ubuntu 24.04/26.04 server that LESta's agent manages (nginx/Apache, BIND9, MariaDB, cron, mail, backups). It does not need — and should not receive — a full checkout of this application's source: only `.install/` (the installer scripts) and the one prebuilt agent binary at `agent/dist/lesta-agent-linux-amd64`.

`install.sh`, at the repository root, fetches exactly that subset via a real git partial clone plus sparse-checkout, never the whole application. Download it, read it, then run it — never pipe a network fetch straight into a shell:

```sh
curl -fsSL -o install.sh https://raw.githubusercontent.com/mikho/LESta/main/install.sh
less install.sh   # read it before running it -- it's short, and only ever runs git
chmod +x install.sh
sudo ./install.sh --dry-run --dest /opt/lesta
sudo ./install.sh --apply --yes --dest /opt/lesta
```

Re-running the same command later updates `/opt/lesta` in place rather than re-cloning. See `install.sh --help` for `--repo`/`--ref` overrides (a fork, or a pinned release).

From there, continue with the in-app **Installation Guide** (`/docs/installation-guide`, or `resources/docs/installation-guide.md`) for enrolling the node and installing services.

## Developing the control-plane application

This is a standard Laravel 13 + Inertia v3 + React application.

```sh
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
composer run dev
```

Tests: `php artisan test --compact` (Pest). Static analysis: `vendor/bin/phpstan analyse`. Formatting: `vendor/bin/pint`, `npm run lint`, `npm run format`.
