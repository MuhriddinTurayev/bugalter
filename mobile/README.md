# Bugalter Mobile

Flutter mobile app (Android and iOS, one code base) for the Bugalter accounting platform.
Glass (translucent) design, smooth animations, three languages (Oʻzbekcha, Русский, Тоҷикӣ), dark and light themes,
responsive from small phones to tablets (a side rail replaces the bottom bar on wide screens).

## What it does

Dashboard (balances, month result, week / 6-month charts, expense split, debts, tax deadlines) · Bank and cash
(accounts, transactions, categories) · Documents (invoices and acts, send / pay / cancel) · Contractors · Payroll
(PIT, INPS, social tax from the rules table, approve / reopen / pay) · Employees · Taxes (calendar, report preparation,
approval workflow, sandbox submission) · AI accountant (on-device rules, answers only from the books) · AI category
proposals · Voice messages in the AI chat (phone speech recognition, answers can be read aloud; the AI button floats and can be dragged anywhere) · Search · Notifications · Audit history · Settings, company, users and roles.

## Rules kept from the backend (CLAUDE.md)

- Money is an integer number of tiyin everywhere; never a float.
- Tax rates and deadlines are read from `assets/rules.json`, a copy of `docs/tax/rules.json`; nothing is hardcoded.
  Reports carry the "unverified" badge. Nothing is submitted without a person's approval (sandbox receipt `SBX-…`).
- The AI accountant cannot sign, approve or submit. Every action is written to the audit log with its actor.

## Data

The app is offline-first: the books are kept on the device (shared_preferences, JSON) and seeded with a demo company.
Demo logins (password `Demo#2026pass`): `owner@bugalter.test`, `accountant@bugalter.test`, `cashier@bugalter.test`.

### Real company on the server

On the sign-in page the **Server** tab signs in to a real company (`mening` → `https://mening.<platform domain>`, or a full
address; `localhost` / IP addresses use plain http for local tests). It uses the Laravel mobile API (`routes/mobile.php`,
`/api/mobile/*`): a stateless signed token bound to the company, password and expiry; the same policies, permissions,
plan limits, numbering and audit log as the web pages.

- Reads: the books, tax cabinet, letters, Didox, lawyer's drafts, advances, KPI and signing keys are read from the server
  and cached on the phone (works offline, pull to refresh, refresh on start / resume). Tax amounts come only from the server.
- Writes from the phone: money movements, contractors, documents and their status. They change the phone's copy at once and go
  through a persisted outbox (in order, with the phone's temporary negative ids mapped to the server's real ones); when the
  server refuses one, the person is told why and the server's truth is read back.
- Not done from the phone (read-only in server mode): payroll, accounts, employees, tax report actions, payments, signing.
  The phone never signs and never pays; those stay with a person on the web.

## Run

```powershell
cd mobile
flutter pub get
flutter emulators --launch <emulator-id>
flutter run
flutter test
```

iOS builds need a Mac with Xcode: `flutter build ios`. The Android APK: `flutter build apk --release`.
Keep `assets/rules.json` in sync with `docs/tax/rules.json`.
