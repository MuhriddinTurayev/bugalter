# Bugalter

A multi-tenant accounting platform for companies in Uzbekistan, with an AI accountant (ChatGPT, Claude, Gemini or DeepSeek),
deterministic tax calculators, payroll and an approval-gated tax submission flow.
See [CLAUDE.md](CLAUDE.md) for the rules and [docs/PROGRESS.md](docs/PROGRESS.md) for what is built.

Stack: Laravel 13 · PHP 8.3+ · MySQL 8.4 · Blade + Alpine.js + Tailwind CSS 4 (Vite) · Chart.js · Pest · `stancl/tenancy` · `anthropic-ai/sdk`.

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

The project runs under OSPanel with **PHP 8.4**, Apache and the **MySQL-8.4** module. Use OSPanel's PHP, not another one on
the PATH (paths below are this machine's; adjust them to your OSPanel folder).

```powershell
$env:Path = "C:\backend\OSPanel\modules\PHP-8.4;$env:Path"
$env:COMPOSER_HOME = "C:\backend\OSPanel\data\PHP-8.4\default\composer"
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
DB_HOST=127.0.1.31          # the MySQL-8.4 address shown in OSPanel
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

SIGNING_MASTER_KEY=base64:...       # php -r "echo 'base64:'.base64_encode(random_bytes(32));"  (required outside local)
OPENAI_API_KEY=                     # optional: db:seed copies these into Platform → Integrations (local only)
ANTHROPIC_API_KEY=
GEMINI_API_KEY=
DEEPSEEK_API_KEY=
```

Create an empty MySQL database `bugalter` (utf8mb4), then:

```powershell
php artisan migrate:fresh --seed
```

This creates the platform tables, the tax rules (in every environment: they are rules, not demo data), 3 sample plans (no
prices), the two platform staff accounts below, and **two real companies** (`demo1`, `demo2`), each in its own database with
demo data: a year of documents and payments, three months of payroll, tax reports in several states (accepted through the
sandbox, waiting for approval, inside the cancel window), a mandate and a sandbox ERI key (demo1: autopilot level 2, server
signing; demo2: level 1, signing on the owner's device) and unclassified bank lines with proposals. It drops every `tenant*`
database first, so it is safe to repeat. No demo data is seeded outside `APP_ENV=local`.

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
hardcoded) · Staff (invite, disable, roles) · Audit log with filters · Kill switch for autopilot · Profile (password, 2FA) ·
Subscription end date per company · **Tax rules** (correct a value, verify it) · **Integrations** (AI provider keys for ChatGPT,
Claude, Gemini and DeepSeek with a connection test; the tax authority gateway; the signer service).

## What a company can do

| Area | What it does |
|---|---|
| **Taxes** (`/tax`) | Tax calendar from the company's regime; VAT (monthly), turnover tax, profit tax (year to date), PIT and social tax. Every figure comes from the deterministic calculators in `app/Tax` and the rules tables; data-quality issues block a submission. |
| **Submission** | Ask for approval → the owner approves (or an active mandate does, at autopilot level 3) → a cancel window (24 h by default) → hard-stop gates → signing → the gateway. Every step is in the audit log and the signature log. A delivery without a definite answer is *unknown* and is never resent until it is verified. See [docs/SUBMISSION.md](docs/SUBMISSION.md). |
| **Desktop agent** (`/tax/agent`, `agent/`) | A Windows program drives a real browser on my.soliq.uz with the official E-IMZO app, no Soliq API needed. The key never leaves E-IMZO; Laravel checks the filled form before the agent may sign. See [agent/README.md](agent/README.md). |
| **Automatic preparation** (`/tax/runs/new`) | "Hisobotlarni avtomatik tayyorlash": pick a month → background analysis with live steps → per-report checklist → approval of the reviewed versions with a declaration → each report submitted and tracked on its own. |
| **Payroll** (`/payroll`) | From active employees: PIT, INPS (inside PIT), social tax, net pay. Draft → approved → paid. |
| **AI accountant** (`/ai`) | Chat with tools over the company's books (calendar, tax calculator, drafts, category proposals, approval requests). It cannot sign, see keys or change mandates, autopilot, roles or security settings. |
| **AI proposals** (`/ai/proposals`) | Category suggestions from the company history and from the AI; applied after approval or by the owner's auto-apply rule. |
| **Autopilot** (`/autopilot`, owner) | Levels 0-3, cancel window, signing mode (vault or the owner's device with E-IMZO), mandates, kill switch. |
| **ERI keys** (`/signing-keys`, owner) | Upload a .pfx into the encrypted vault (envelope encryption with `SIGNING_MASTER_KEY`), expiry warnings, revoke, signature log. |
| **Settings** | Company profile and tax regime, people (invite, roles, extra permissions), employees, AI provider and the company's own API key, audit log. |

**Sandbox vs real.** The gateway and the signer start in *sandbox* mode: a report is validated, "signed" with a test signature
and "accepted" with an `SBX-…` receipt, and nothing leaves the application. A real submission needs an agreement with the tax
authority or an e-reporting operator (their API goes into Integrations → HTTP gateway), an E-IMZO signing server (Integrations →
signer service) and the legal check in PLAN.md. Until an accountant verifies the tax rules, autopilot submissions are blocked.

**AI keys.** Enter them in the panel (Integrations), or let a company owner enter the company's own key (Settings → AI). Keys
are stored encrypted, never shown again and never sent to the browser or the AI. Model names are editable because providers
rename models (defaults: `gpt-5`, `claude-opus-5-5`, `gemini-2.5-flash`, `deepseek-chat`).

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
| `php artisan tax:rules-sync` | load `docs/tax/rules.json` into the rules tables |
| `php artisan bugalter:autopilot` | autopilot pass and due submissions for every company (scheduled hourly) |
| `php artisan bugalter:daily` | overdue invoices, tax deadlines, ERI key expiry (scheduled daily at 08:00) |
| `php artisan schedule:work` | run the scheduler locally (production: `* * * * * php artisan schedule:run`) |

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
- Tax rates and deadlines are never hardcoded: they live in `docs/tax/rules.json` → the rules tables, versioned. The values
  were filled in from the Tax Code and must be verified by an accountant (Platform → Tax rules) before autopilot submissions run.
- The platform kill switch stops every mandate (autopilot) submission; a company owner has their own kill switch on the
  Autopilot page.
- Plan limits (users, documents and transactions per month) are enforced; after the subscription end date a company is read-only.
