# Bugalter

## Project goal

A multi-tenant accounting platform for companies in Uzbekistan, with an AI accountant built in.
Each company (tenant) keeps its accounting data in its own isolated database.
Tax amounts and deadlines come only from deterministic, versioned rules, never from the AI.
The AI accountant does the accounting work end to end, but nothing is submitted to the tax authority without the company owner's approval or mandate.

## Key documents

- `docs/PLAN.md`: the project plan, split into Prompt 0 to Prompt 26.
- `docs/PROGRESS.md`: progress checklist. Tick a prompt when it is finished.
- `docs/design/reference.png`: UI design reference (see "UI design" below).
- `docs/tax/`: source data for the tax rules tables (`rules.json`, explained in `rules.md`). `TaxRuleSeeder` and `php artisan tax:rules-sync` read from here.

## Stack

- **Backend:** Laravel (latest stable, currently 13.x) on PHP 8.3+.
- **Frontend:** Blade + Alpine.js + Tailwind CSS, bundled with Vite. Chart.js for charts. No Livewire, no Vue, no React.
- **Database:** MySQL 8 with `utf8mb4`.
- **Multi-tenancy:** `stancl/tenancy`, one database per tenant.
- **Roles and permissions:** the `Role` enum plus `App\Enums\Permission` (role defaults and owner grants in `user_permissions`), checked through Gates.
- **AI providers:** ChatGPT, Gemini and DeepSeek through their OpenAI-compatible Chat Completions APIs (Laravel HTTP client); Claude through the official `anthropic-ai/sdk`.
- **Authentication and 2FA:** Laravel Fortify.
- **Background work:** Laravel queues on Redis, Laravel scheduler.
- **Documents:** `barryvdh/laravel-dompdf` for PDF, `maatwebsite/excel` for Excel.
- **Money:** `brick/money`.
- **Tests and code style:** Pest, Laravel Pint.

Packages are added in the prompt that first needs them, so not all of them are installed yet.

## Rules

### Language and localization

- Code, identifiers, comments and commit messages are in English.
- All user-facing text goes through Laravel localization. `lang/uz` (Uzbek, Latin script) is the default locale and `lang/ru` is the second one.
- Never hardcode UI strings in Blade. This also covers validation messages, notifications, emails, PDFs and Excel headings.

### Multi-tenancy

- Every company (tenant) has its own MySQL database. Never mix data from different tenants in one query.
- Tenant data is accessed only through the tenancy context. No hand-built connections to a tenant database and no cross-database joins.
- Queued jobs and scheduled tasks that touch tenant data must run inside the tenant's context.

### Money

- Money is stored as `BIGINT` in minor units (tiyin; 1 so'm = 100 tiyin) and handled with `brick/money` or an equivalent.
- Never use float for money: not in columns, casts, PHP calculations or JavaScript.

### Tax

- Tax calculations are done only by deterministic PHP code using a versioned rules table.
- Never invent tax rates or deadlines and never hardcode them. Read them from the rules tables, which are seeded from `docs/tax/`.
- If a rate or deadline you need is not in `docs/tax/`, stop and ask instead of guessing.
- The values in `docs/tax/rules.json` were filled in from the Tax Code and are `needs_verification` until an accountant verifies them in the platform panel. Calculations show an "unverified" badge; the `rules_verified` gate blocks autopilot/mandate submissions until then. Change a value only with a new `effective_from` row, never in code.

### AI accountant

- The AI accountant does the accountant's work end to end: it collects data, classifies transactions, prepares payroll, calculates taxes through the deterministic calculators and prepares reports.
- Submission to the tax authority happens ONLY with the company owner's permission, in one of two forms: a per-report approval, or a standing mandate (autopilot) configured by the owner. With no permission nothing is ever submitted.
- The permission check, the hard-stop gates and the signing are enforced by deterministic code (`SubmissionOrchestrator` and the `Signer` service), never by the AI's judgment. The AI can only call `request_submission(report_id)`. It cannot sign, cannot choose a key, and cannot change mandates, autopilot settings, roles or security settings.
- E-signature (ERI/E-imzo) private keys and their passwords are secrets: stored only encrypted in the vault, decrypted only inside the isolated `Signer` service, and never exposed to the browser, logs, exceptions, queue payloads, error reports or the AI context.
- Tax amounts come only from the deterministic calculators. Rates and deadlines come only from the `docs/tax/` rule tables. Tenant isolation is strict. Money is integer minor units.
- Every autonomous action is performed by an explicit actor (`user`, `ai` or `autopilot`) and is written to the audit log with the reason and the approval/mandate reference.

### Code structure

- Form Requests for validation.
- Policies and permissions for authorization.
- Eloquent API Resources or simple DTOs where useful.
- Keep controllers thin. Business logic lives in Actions and Services.

### Platform admin panel

- The platform owner's staff are a separate identity: guard `platform`, table `platform_staff`, own session cookie. Never reuse the company `User` model or guard for them, and never let either sign in on the other's host.
- The root host has no login. The panel is on `admin.<domain>`, companies on `<slug>.<domain>`. `CompanyContext` must run before the session starts, so users are read from the right database.
- 2FA is mandatory for platform staff. The only exception is `TwoFactor::skipAllowed()` (local environment and an explicit setting); check that method, never the config value directly.
- Platform models always use the central connection (`CentralConnection`). Models hydrated inside `$tenant->run()` remember the company connection: build plain arrays there, never render them after the context has ended.
- The panel shows no company business data outside a support access session (written reason, read-only, time-limited, audited on both sides).
- Never seed a default password outside `APP_ENV=local`; real environments use `php artisan platform:create-admin`.
### Definition of done

After every task, run these and fix any failure before finishing:

```sh
php artisan test
vendor/bin/pint
npm run build
```

Then tick the prompt in `docs/PROGRESS.md`.

## Local environment

The project runs under OSPanel with the PHP-8.4 module (`.osp/project.ini`) and the MySQL-8.4 module. Use OSPanel's `modules\PHP-8.4\php.exe` (on this machine `C:\backend\OSPanel\modules\PHP-8.4`, set `COMPOSER_HOME=C:\backend\OSPanel\data\PHP-8.4\default\composer` for its `composer.bat`), or the OSPanel console for this project. Do not use another PHP on the PATH.

## UI design

The reference is `docs/design/reference.png`. Its visual language:

- Light gray page background. Content sits on white cards with large rounded corners and generous spacing.
- One deep-green accent for primary buttons, active states, badges and charts. Everything else is neutral gray and near-black.
- Header: logo on the left, pill-shaped tab navigation in the center (the active tab is a white pill), then search, notifications and the user avatar on the right.
- Left side: a vertical icon rail, grouped into rounded white capsules.
- Dashboard blocks: KPI cards with large figures (the decimal part muted), small green percentage badges, a rounded bar chart with the current bar highlighted, an area line chart with a green gradient fill, and tables with status pills.
- The reference uses placeholder English text and dollar amounts. In the app, all text is localized and amounts are in UZS.
