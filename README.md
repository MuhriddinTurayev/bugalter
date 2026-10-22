# Bugalter

A multi-tenant accounting platform for companies in Uzbekistan, with an AI accountant planned.
See [CLAUDE.md](CLAUDE.md) for the rules and [docs/PROGRESS.md](docs/PROGRESS.md) for what is built.

Stack: Laravel 13 · PHP 8.3+ · MySQL 8 · Blade + Alpine.js + Tailwind CSS 4 (Vite) · Chart.js · Pest · `stancl/tenancy`.

## How it is organised

| Host | What it is | Login |
|---|---|---|
| `bugalter` (root) | Landing page only | **none**: `/login` is a 404 |
| `admin.bugalter` | **Platform admin panel**: the platform owner's staff | own guard, own table (`platform_staff`), own session cookie, mandatory 2FA |
| `<slug>.bugalter` (e.g. `demo1.bugalter`) | One company, with its **own MySQL database** (`tenant<slug>`) | company users (owner, chief accountant, cashier) |

Company users cannot sign in on the panel, platform staff cannot sign in on a company host, and the two sessions use
different cookie names. The panel never shows a company's business data, except in a reasoned, read-only,
time-limited, audited *support access* session.

## Local setup (Windows + OSPanel)

The project runs under OSPanel with **PHP 8.4**, Apache and MySQL. (Not Herd: this machine has no Herd. The `php` on the
system PATH is 8.1 and must not be used.)

```powershell
$env:Path = "D:\OSPanel\OSPanel\modules\PHP-8.4;$env:Path"
php -v                      # must print 8.3 or newer

copy .env.example .env
php artisan key:generate
composer install
npm install
npm run build
```

Edit `.env` for OSPanel's MySQL and the local platform settings:

```ini
APP_ENV=local
APP_URL=https://bugalter
DB_HOST=127.0.1.29          # the MySQL address shown in OSPanel
DB_DATABASE=bugalter
DB_USERNAME=root
DB_PASSWORD=
QUEUE_CONNECTION=database
CACHE_STORE=database
MAIL_MAILER=log             # invitation emails are written to storage/logs/laravel.log

SESSION_DOMAIN=null         # keep cookies per host: a company session must never reach another host
SINGLE_COMPANY=false
PLATFORM_DOMAIN=bugalter
PLATFORM_PATH_FALLBACK=true         # also serve the panel at <root host>/platform (local only)
PLATFORM_LOCAL_DOMAINS=localhost    # companies also answer on <slug>.localhost (local only)
PLATFORM_ADMIN_2FA_OPTIONAL=true    # local only: lets you skip 2FA enrolment (red banner shown)
```

Create an empty MySQL database `bugalter` (utf8mb4), then:

```powershell
php artisan migrate:fresh --seed
```

This creates the platform tables, 3 sample plans (no prices), the two platform staff accounts below, and **two real
companies** (`demo1`, `demo2`), each in its own database with demo data. It drops every `tenant*` database first, so it is
safe to repeat. Nothing here is seeded outside `APP_ENV=local`.

## Opening the panel and the companies locally

`admin.bugalter` and `demo1.bugalter` **do not resolve on this machine**: OSPanel generates its Apache virtual host only for
`bugalter` and `www.bugalter`, and I could not make it serve subdomains from the project. Pick one:

**A. `php artisan serve` with `*.localhost` (recommended, nothing to configure).** Browsers resolve every `*.localhost`
name to your own machine, with no hosts file and no certificate:

```powershell
php artisan serve --port=8765
```

| What | URL |
|---|---|
| Platform admin panel | http://localhost:8765/platform |
| Company demo1 | http://demo1.localhost:8765 |
| Company demo2 | http://demo2.localhost:8765 |
| Root host (landing, no login) | http://localhost:8765 |

**B. Through OSPanel, panel only:** https://bugalter/platform (the path fallback; it exists only when `APP_ENV=local`
and `PLATFORM_PATH_FALLBACK=true`). Companies cannot be opened this way.

**C. Real subdomains through OSPanel (not set up).** This needs three things I could not do from the project:
1. hosts-file lines (Notepad as administrator, `C:\Windows\System32\drivers\etc\hosts`):
   ```
   127.0.1.11 admin.bugalter
   127.0.1.11 demo1.bugalter
   127.0.1.11 demo2.bugalter
   ```
2. OSPanel's Apache must be told to answer these names for the `bugalter` project (aliases or a wildcard host), and
   OSPanel regenerates its configuration, so changing `httpd.conf` by hand does not last;
3. the project certificate (`D:\OSPanel\OSPanel\data\ssl\projects\bugalter\cert.crt`) only covers the names OSPanel
   knows. A wildcard certificate is needed, and the issuing CA must be trusted: run `certmgr.msc`, import the CA
   certificate into *Trusted Root Certification Authorities*, and restart the browser.

Until then the company links shown in the panel (`https://demo1.bugalter`) do not open on this machine; the same company
answers on `http://demo1.localhost:8765`.

## Accounts (development only)

**Platform panel** (`/platform`). Never use these passwords anywhere real. They exist only because `APP_ENV=local`.

| Account | Password | Role |
|---|---|---|
| `admin@bugalter.local` | `Platform#Dev2026` | super admin: everything |
| `support@bugalter.local` | `Support#Dev2026` | support: sees companies, can open support access; cannot create or change |

Two-factor authentication is mandatory. At the first sign-in you are sent to enrolment (QR code and recovery codes).
With `PLATFORM_ADMIN_2FA_OPTIONAL=true` **and** `APP_ENV=local` a "skip" button appears and a red banner
"2FA is disabled in local mode" is shown on every page. In any other environment the skip returns 403, whatever the
setting says (covered by tests).

**Companies** (`http://demo1.localhost:8765` and `http://demo2.localhost:8765`; same logins, separate databases):

| Login | Role | Password |
|---|---|---|
| `owner@bugalter.test` | Owner | `Demo#2026pass` |
| `accountant@bugalter.test` | Chief accountant | `Demo#2026pass` |
| `cashier@bugalter.test` | Cashier | `Demo#2026pass` |

### The first admin in a real environment

No default password ever exists outside `local`. Create the first super admin interactively (the password is asked for
and never passed as an argument):

```powershell
php artisan platform:create-admin you@example.com "Your Name"
```

Add `--support` to create a support user instead. 2FA must then be enrolled at the first sign-in.

## What the panel does

Dashboard (companies by status, new this month, problems, recent audit events) · Companies (search, filters, link with copy
button, plan, status, last activity, database size) · Create company (live slug check; creates the database, runs all
migrations, creates the company profile and an invited owner, shows the ready link) · Company detail (change plan,
suspend / restore, resend the owner's invitation, support access) · Plans (prices and limits are entered here and never
hardcoded) · Staff (invite, disable, roles) · Audit log with filters · Kill switch for autopilot · Profile (password, 2FA).

Support access needs a written reason (min. 10 characters), is read-only, expires after 30 minutes
(`PLATFORM_SUPPORT_ACCESS_MINUTES`), and is written both to the platform audit log and to the company's own audit log
(Settings → Audit, owner only).

## Commands

| Command | What it does |
|---|---|
| `php artisan serve --port=8765` | local server (use with `*.localhost` above) |
| `composer dev` | server + queue + Vite |
| `composer test` | `php artisan test` (Pest) |
| `composer lint` | `pint --test` |
| `composer analyse` | Larastan level 5 |
| `vendor/bin/pint` | fix code style |
| `npm run build` | production assets (do not run while `npm run dev` is running) |
| `php artisan platform:create-admin` | create a platform staff account |

## Docker alternative

`docker compose up -d` starts MySQL 8.4, Redis and Mailpit with persistent volumes; the defaults in `.env.example` match it
(user `bugalter`, password `secret`, queue and cache on Redis, mail in Mailpit at http://localhost:8025). The MySQL user is
granted rights on `tenant%` databases so companies can be created.

## Single company dev mode

`SINGLE_COMPANY=true` serves one company from the default database on the root host (the original development setup, and
what the older test suite uses). Leave it `false` for the platform: the root host then has no login.

## Rules worth knowing

- Money is stored as integer minor units (tiyin) and calculated with `brick/money`; never floats.
- UI text lives in `lang/uz` and `lang/ru`; never hardcode strings in Blade.
- A company's data is reached only through the tenancy context; the platform models always use the central connection.
- Tax rates and deadlines are never hardcoded. The VAT percent on documents is typed by the user; the demo data uses a
  sample value that is **not** a tax rule. Plan prices are typed in the panel; none are seeded.
- The panel's kill switch only stores its state and audits it: the autopilot it must stop does not exist yet.
