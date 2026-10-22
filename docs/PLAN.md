# Project plan

> Only the sections changed by the product-direction update (3, 5, 6, 9 and "Legal check") are written here. The other sections of the original plan were not in the repository and still need to be added.

## 3. Roles and permissions

Roles are managed with `spatie/laravel-permission`, per tenant. New permissions:

| Permission | Allows |
|---|---|
| `reports.approve` | Approve a prepared report for submission (per-report approval). |
| `reports.submit` | Trigger submission of an approved report. |
| `signing.keys.manage` | Upload, replace and revoke ERI/E-imzo signing keys in the vault. |
| `autopilot.manage` | Create, change and revoke mandates and autopilot levels. |
| `payroll.approve` | Approve a prepared payroll run. |

By default only the **owner** role holds `autopilot.manage` and `signing.keys.manage`, and only the owner can grant them to anyone else. The AI actor holds none of these permissions.

## 5. Tax submission flow

1. The AI (or a user) prepares a report. Amounts come only from the deterministic calculators and the `docs/tax/` rule tables.
2. The AI calls `request_submission(report_id)`. This creates an **approval request**; it does not submit anything.
3. `SubmissionOrchestrator` (deterministic code) decides whether submission may proceed:
   - **Per-report approval:** a user with `reports.approve` approves this specific report version. Any change to the report voids the approval.
   - **Mandate (autopilot):** a written standing authorization set up by the owner, scoped to report types, tax periods and an amount limit, with an expiry date.
   - With neither, nothing is submitted.
4. **Hard-stop gates** run before signing, regardless of mandate or autopilot level. Any failed gate blocks submission and notifies the owner:
   - report totals do not match the deterministic recalculation;
   - unresolved data-quality issues (unclassified transactions, missing documents);
   - amount or change versus the previous period above the mandate limit;
   - deadline or period outside the mandate scope;
   - signing key missing, expired or revoked;
   - tax authority validation errors.
5. **Cancel window:** after approval or autopilot trigger and before signing, there is a configurable delay (default 24 hours) in which the owner can cancel. The owner is notified when it starts.
6. `SubmissionOrchestrator` calls the `Signer` service to sign, then sends the report. The result is written to the audit log.
7. **Kill switch:** the owner can disable all autonomous submission instantly. It sets autopilot to level 0 for the tenant and cancels all pending cancel windows.

### Autopilot levels

| Level | Name | Behavior |
|---|---|---|
| 0 | Off | The AI does not prepare reports. |
| 1 | Prepare | The AI prepares reports. A human approves and submits each one. |
| 2 | Ask | The AI prepares reports and asks the owner for approval. After approval it submits. |
| 3 | Auto | Under an active mandate, and if all gates pass, the AI submits when the cancel window ends. |

Levels 2 and 3 require an active mandate. Level 3 also requires a signing key in the vault.

### Signing vault and Signer service

- ERI/E-imzo private keys and passwords are stored only in the vault, encrypted (see section 9).
- Signing happens only in the separate `Signer` service, which has no access to the AI, the browser or the tenant database. It receives a document hash and a key reference and returns a signature.
- **Actors:** every action is performed by `user`, `ai` or `autopilot`, and the audit log records the actor, the reason and the approval or mandate reference.
- **DeviceSigner:** signing in the owner's browser with the E-IMZO plugin. Always available as an alternative to server-side signing.

## 6. AI accountant

- The AI does the accountant's work: collects data, classifies transactions, prepares payroll, calculates taxes by calling the deterministic calculators, and prepares reports.
- Data writes are **proposals**. A user approves them, unless the owner has enabled auto-apply for low-risk actions (for example, classifying a transaction with a high-confidence match to an existing rule). Auto-apply is configured per action type and logged with actor `ai`.
- Submissions go only through `request_submission(report_id)`.
- The AI cannot sign, choose or read keys, or change mandates, autopilot settings, roles or security settings. These actions are not exposed as tools.
- The AI never calculates tax amounts itself. Its context never contains keys, passwords or signatures.

## 8. Platform admin panel

The platform owner (the owner of the whole service) is different from a company owner.

- **Hosts:** the root host has no login; the panel lives on `admin.<domain>`; each company lives on `<slug>.<domain>` with its own MySQL database (`stancl/tenancy`, database per tenant).
- **Separate identity:** guard `platform`, table `platform_staff` (roles `super_admin`, `support`), own session cookie, shorter session lifetime. Company users cannot sign in on the panel; platform staff cannot sign in on a company host.
- **2FA is mandatory** (TOTP, recovery codes stored hashed, replay protection). Skipping enrolment is possible only when `APP_ENV=local` and `PLATFORM_ADMIN_2FA_OPTIONAL=true`, with a red banner; it is a hard 403 everywhere else.
- **First admin:** `php artisan platform:create-admin` (interactive password). A dev admin is seeded only in `local`.
- **Companies:** created through `TenantProvisioningService` (database, migrations, subdomain, company profile, invited owner). Statuses: provisioning, active, suspended, failed. Plans carry prices and limits that are entered in the panel, never hardcoded.
- **No business data in the panel.** The only exception is *support access*: a written reason (min. 10 characters), read-only, time-limited (default 30 minutes), written to the platform audit log and to the company's own audit log, which its owner can read.
- **Platform audit log:** append-only (the model refuses updates and deletes).
- **Kill switch:** a platform-level switch that stops automatic submission for every company; it has its own audit trail. The autopilot gates (U5) must check `KillSwitch::isActive()` first.
## 9. Security

- **Key vault with envelope encryption:** each ERI key is encrypted with a per-key data key. The data key is encrypted with a master key held outside the application database (KMS or HSM; for local development an environment secret). Key passwords get the same treatment.
- **Separate signer:** the `Signer` service runs as its own process with its own credentials and network rules. It is the only place a key is decrypted, and only in memory for the duration of one signing operation.
- **Signature log:** every signing is recorded (time, tenant, key reference, document hash, actor, approval or mandate reference). It never stores keys, passwords or document contents.
- **Key expiry monitoring:** the scheduler checks certificate expiry. The owner is warned 30, 14 and 3 days before. After expiry the key cannot be used and autopilot drops to level 1.
- **No leaks:** keys and passwords must not appear in the browser, logs, exceptions, queue payloads, error reports or the AI context.
- **Incident runbook:** on a suspected key compromise:
  1. Press the kill switch.
  2. Revoke the key in the vault.
  3. Revoke the certificate with the issuing authority.
  4. Rotate the master key.
  5. Review the signature log and audit log for unauthorized submissions.
  6. Notify the owner and, if needed, the tax authority.

## Legal check

- Storing ERI keys on the server and signing automatically must be confirmed with a lawyer and with the ERI issuing authority before this feature goes live.
- The owner must sign a written authorization (the mandate) before autopilot can be enabled.
- Fallback: `DeviceSigner` (signing in the owner's browser with the E-IMZO plugin) must always remain available.
