# Report preparation and submission

How Bugalter prepares tax reports, gets a person's approval, submits them, and confirms what the tax authority
actually received. It covers what is built, how to configure it, and what still needs official access.

## The workflow ("Hisobotlarni avtomatik tayyorlash")

`/tax` → **Hisobotlarni avtomatik tayyorlash** (`/tax/runs/new`).

| Step | Page | What happens | Code |
|---|---|---|---|
| 1. Company and period | `/tax/runs/new` | Company name, STIR, regime, VAT status, data sources (books, tax cabinet, ERI key, submission channel) and a month. | `ReportRunController@create` |
| 2. Analysis | `/tax/runs/{run}` | A queued job runs six steps and records the real outcome of each (`done`, `skipped` with a reason, `warning`, `failed`). The page polls `/tax/runs/{run}/status`. | `RunReportWorkflow` → `App\Tax\ReportWorkflow` |
| 3. Review | same page | One row per report type: period, deadline, tax, amount payable, source-data counts, checklist status, errors, warnings, submission status. | `ReportWorkflow::rows()`, `ReportChecklist` |
| 4. Approval | `/tax/runs/{run}/approve` | Selected reports with their version hashes, company identity, total, unresolved warnings, a declaration checkbox and **Tanlangan hisobotlarni tasdiqlash va topshirish**. | `ApproveReportsRequest`, `BatchApproval` |
| 5. Submission | same run page | Each report is sent by its own job; its status (accepted, rejected, unknown, …) and receipt are shown. | `SubmitApprovedReport` → `SubmissionOrchestrator` |

Analysis steps (`ReportRun::STEPS`):

1. **profile**: the company's identity and regime from the settings. A missing STIR is a warning.
2. **portal**: a tax cabinet visit (`TaxPortalCheck`), only with the owner's consent, a configured robot and a usable key. Otherwise it is *skipped* and the reason is shown, never shown as done.
3. **obligations**: every report type with the period of its kind that contains the month. Types the regime does not owe are listed as *not required*. An empty cabinet list is never taken as "no obligation".
4. **data**: documents, transactions, unclassified transactions and payroll runs per period. A period with no data is a warning; it is never assumed to be a nil return.
5. **prepare**: `TaxReportPreparer` (deterministic calculators, versioned rules). Reports under an approval in progress, or already sent, are never recalculated.
6. **validate**: data-quality issues plus the gateway's own pre-submission checks.

Checklist statuses (`ReportChecklist`): not checked, required, not required, data missing, draft prepared, validation failed,
ready for approval, approved, in progress, submitted, accepted, rejected, unknown. The review recomputes them from the current
state of each report, so the page never shows a stale status.

## Approval is enforced on the server

- `SubmissionOrchestrator::assertApprovable()` checks the permissions (`reports.approve`, plus `reports.submit` for immediate
  submission), the report status, blocking issues, open deliveries, and that the report still has the version hash the person
  reviewed. The single-report forms and the batch form both post that hash.
- `approve()` locks the report row (`lockForUpdate`, the same lock the preparer takes) and refuses if the figures changed
  in the meantime.
- Batch approval is all or nothing: if any selected report changed or cannot be approved, none is approved.
- Every approval is written to `report_approvals`: source (user or mandate), user, version hash, period, amount, company STIR,
  declaration, IP address and user agent, time. A row can only be *invalidated* (figures changed, cancelled, re-approved, or
  not received by the authority); it is never edited or deleted.
- The AI still has only `request_submission(report_id)`. Nothing in this workflow gives it more.

## Submission safety

| Situation | What the code does |
|---|---|
| Before anything leaves the app | A `submission_attempts` row (`sending`) and the report status `submitting` are written first. |
| Idempotency | `SubmissionAttempt::keyFor()`: `sha256(tenant, report, approved version, number of definite rejections)`. It is sent as the `Idempotency-Key` header and in the body. A resend after "not received" reuses the key; a definite rejection or a new version gets a new one. |
| Timeout, dropped connection, 5xx, 408/409/425/429, exception after signing | Outcome **unknown**: report status `unknown`, never resent. `submit()` and `approve*()` refuse while any attempt is `sending`, `submitted` or `unknown`. |
| 4xx | Definite **rejected**; the report can be corrected and approved again. |
| 2xx without a known status | **submitted** (received, not yet decided), never shown as accepted. |
| Worker died mid-delivery | `SubmissionVerifier::recoverInterrupted()` turns a `sending` attempt older than 20 minutes into `unknown`. |
| Verification | `SubmissionVerifier::verifyPending()` runs hourly (in `bugalter:autopilot`) and on the **Holatni tekshirish** button. *accepted*/*rejected* are recorded with the receipt. *not_found* (only when the authority says so) sends the report back to *prepared* for a fresh approval. *unknown* changes nothing. |
| Portal gateway verification | Only a cabinet visit that **started after** the delivery counts. A report missing from the cabinet list stays *unknown*, because the list may lag. |
| Manual reconciliation | On the report page a person with approve **and** submit permission records *accepted* (receipt required), *rejected*, or *not received* (only while the outcome is *unknown*), with a note of at least 10 characters that goes to the audit log. |

Green (`accepted`) appears only from the authority's own answer or a person's recorded reconciliation. Sandbox receipts
(`SBX-…`) are labelled as such everywhere.

## Configuration

Platform panel → **Integrations** → tax authority gateway:

| Driver | Status |
|---|---|
| `sandbox` (default) | Validates and "accepts" locally. **Nothing is sent.** |
| `http` | Bugalter's own operator contract (see `HttpGateway`): `POST {base_url}{submit_path}` with `Idempotency-Key`, and optionally `GET {base_url}{status_path}?idempotency_key=…` → `accepted / rejected / submitted / not_found`. With no status path, open outcomes are settled by hand. This is **not** a published Soliq API; an operator with another format needs a small adapter. |
| `portal` | The tax cabinet robot protocol (`RobotPortal`). The robot service is not part of this repository. |

Deployment:

```sh
php artisan migrate                # central database
php artisan tenants:migrate        # adds report_approvals, submission_attempts, report_runs to every company database
php artisan queue:work redis       # RunReportWorkflow and SubmitApprovedReport are queued jobs
* * * * * php artisan schedule:run # hourly bugalter:autopilot also verifies open deliveries
```

## What is tested

`tests/Feature/SubmissionSafetyTest.php`:
- a dropped connection becomes *unknown* and every resend path is refused;
- 5xx, 409 and 429 become *unknown*, and a 422 becomes *rejected*;
- the idempotency key is sent, and a new key is used only after a rejection;
- verification records *accepted* with the receipt, and records *rejected*;
- after *not_found*, a fresh approval resends with the same key;
- without a status path the outcome stays open and nothing is sent;
- an interrupted delivery is recovered as *unknown*;
- manual reconciliation checks permissions and validation;
- an acknowledged report is never marked "not received";
- portal verification checks the timing of the cabinet visit;
- an approval for figures the person did not see is refused;
- approval records can only be invalidated.

`tests/Feature/ReportWorkflowTest.php`:
- the full run (steps, a skipped portal step, required / not required / not checked);
- blocking data shows *data missing*;
- duplicate runs are refused and the run is queued;
- the declaration is required, and the approval is bound to the reviewed version, through to an accepted report with a sandbox receipt;
- the batch is refused when a report changed after review;
- permissions, and the check that selected reports belong to the run;
- a cancelled approval is never sent by the queued job.

Every test runs on the sandbox gateway or `Http::fake()`. None contacts a real taxpayer account.

## Still needs official access (not built, not claimed)

1. **Official submission channel.** No documented public Soliq API for filing declarations is integrated. A real `http`
   submission needs an agreement with the tax authority or a licensed e-reporting operator, their API documentation and
   credentials, plus an adapter to the contract above (including a status endpoint for automatic verification).
2. **Tax cabinet robot.** The browser-side service behind `RobotPortal` (my.soliq.uz) is not built. It needs written
   confirmation that automated use of the cabinet is permitted, the per-form field mapping, and test access. Bugalter never
   bypasses CAPTCHA or MFA; a challenge it cannot complete through the signed login stops the run with an error.
3. **Legally valid signatures.** The sandbox signer is an HMAC, not an ERI signature. Real server-side signing needs an
   E-IMZO signing service (Integrations → signer) and the legal check in `PLAN.md`. `DeviceSigner` (the owner signs with
   E-IMZO in their browser) works without it.
4. **Tax rules.** `docs/tax/rules.json` is `needs_verification` until an accountant verifies it in the panel. Autopilot and
   mandate submissions stay blocked until then.

## Desktop agent (browser automation on the accountant's computer)

For a company without any filing API. Gateway `desktop` (Platform panel → Integrations). See [agent/README.md](../agent/README.md).

```
Laravel (decides)                              Windows agent (carries out, visible Edge/Chrome)
approve ─ gates ─▶ AgentTask(submit_report) ─▶ claim ─ sign in via E-IMZO ─ check cabinet TIN ─ fill form
                                                  │
                    gates again + AuthorityDocumentCheck ◀─ form-check (values read back)
                    proceed=false ─▶ blocked, nothing signed
                    proceed=true  ─▶ attempt recorded ─────▶ press sign; accountant confirms in E-IMZO
                    complete/fail ◀──────────────────────── cabinet's answer
```

- **Credentials.** Laravel never receives the ERI key or password for this channel; E-IMZO signs in the accountant's session.
  The agent's own token (`bga_…`, only its SHA-256 stored, revocable) is not a signing credential. The signature log keeps
  hashes only (signer `eimzo`). The existing vault, `DeviceSigner` and the `remote` signer stay as they were.
- **Tasks** (`agent_tasks`): `portal_check` and `submit_report`, claimed by one computer with a 5-minute lease that every event extends.
  `needs_user` events (E-IMZO password, CAPTCHA, key choice) are shown on Tax → Agent and notify the owners.
- **Last gate before signing.** `AgentTasks::formCheck()` re-runs the hard-stop gates (totals recomputed from the books,
  data quality, mandate scope for autopilot, kill switches) and compares the form values with the approved report. Only then is the
  attempt written; from there on a failure, a lost lease, or a result without a form check is `unknown`, never "not sent".
- **Before the form check** any failure blocks the report with nothing signed; the owner can approve again.
- **Cabinet reads** go through `TaxPortalCheck::complete()`: `PortalSnapshot::normalize()` (unknown keys dropped, money must be
  integer tiyin), then `PortalAnalyzer`, exactly as for the robot. Portal text is data, never instructions.
- **Verification.** `DesktopGateway::verify()` uses the latest cabinet read that began after the delivery and asks the agent for
  a new one when there is none; absence from the list stays `unknown`.
- **Not built:** the cabinet pages behind the login (see `agent/cabinet-map.json`), which need a recorded signed-in session.

### Final confirmation before signing (desktop agent)

Order for `submit_report`: sign in → identity check → fill the form (nothing signed) → `POST /tasks/{id}/preview` (the values the
agent read back; Laravel compares them with the approved report) → the panel (Tax → Agent) shows the values with
"Topshirilsinmi?" → `agent.send` ("yes") or `agent.hold` ("no": task cancelled, approval withdrawn, report "prepared" again) → the
agent polls `POST /tasks/{id}/decision` → only after "send" does `form-check` work (it answers 409 otherwise) → the hard-stop gates
run again → the attempt is recorded → the cabinet's sign button, E-IMZO confirmation, result. No answer in 30 minutes: nothing is
sent and the task fails before signing.

### Reports the cabinet calculated itself (`tax_reports.source = 'cabinet'`)

For an individual entrepreneur's turnover tax the e-reporting system (form 10190_14) fills the whole calculation itself once the
period is chosen (from e-invoices and online cash registers). Observed 2026-10-24 on the real form, nothing saved:
header selects `na2_id=100`, `type=1`, `year`, `perioddd=M_n`; lines 010 020 030 040 050 060 (rate) 070 0701 080, appendix 0101-0105.

- A cabinet check reads the month before today if the journal does not show it (`agent/src/turnover.js`) and sends it as
  `snapshot.drafts` (normalised by `PortalSnapshot`, codes whitelisted, integer tiyin).
- `TaxReportPreparer::prepareFromCabinet` stores it unchanged (`CabinetReport`): Bugalter checks the form's own arithmetic
  (`cabinet_arithmetic` blocks), the period being over, and notes a rate different from the rule table (`cabinet_rate_differs`).
  The books' calculator never overwrites it; a cabinet-sourced report is **never** submitted by a mandate
  (`cabinet_figures_need_person`).
- Sending: the agent opens the form, chooses the period, reads the figures back, Laravel compares them with the approved report,
  the panel asks "Topshirilsinmi?", and only after "yes" the agent presses the cabinet's own «Отправить» (`#form-send-button`).
  The outcome is taken from the journal (a new report number for the period); anything not established is `unknown`.
  **Not observed yet:** what the cabinet shows after «Отправить» (E-IMZO dialog, confirmation). A dialog the agent does not
  know is shown to the accountant (`waiting_for: browser`) instead of being answered.

### The entrepreneur's cabinet, debts and payments (observed 2026-10-24)

An entrepreneur signs in to my.soliq.uz with the key of the private person and lands in the PRIVATE PERSON's cabinet. Debts, the
tax authority's demands, the payment form and the reports are in the entrepreneur's own cabinet (header switch «Ўтиш»), so the
agent switches (`agent/src/entrepreneur.js`) before it reads or pays anything (`portal.entrepreneur` in the task payload).

- **Debts:** the personal tax card (`agent/src/card.js`): per tax type the latest operation up to today (rows dated later are the
  cabinet's forecast and are never counted): debt, overpayment (prepayment), unpaid penalty. The platform nets it with the payment
  orders made after the card's date and shows the remainder as an estimate.
- **Letters:** requirements ("Солиқ қарзини узиш тўғрисидаги талабнома") and notices, with the unread and unanswered marks.
- **Payments:** the list of payment orders is imported (`cabinet_payments`). A payment is started only from the platform
  (Tax → Payments): tax, payment type and amount typed there are the approval, bound to those values (`tax_payments.version_hash`).
  The agent fills the cabinet's form, reads its confirmation window, Laravel compares tax, type and amount
  (`AgentTasks::paymentCheck`), and only then does the agent press the window's confirm button. A failure after that is `unknown`,
  never retried; it is settled only by the cabinet's list of payment orders (`PaymentOrchestrator::reconcile`).
- **"Check without paying"** (`dry_run`): the same path, but the server never lets it reach the confirm button; the window is
  cancelled. Run live on 2026-10-24: form filled, window read and matched, nothing paid.
- **Not observed yet:** what the cabinet shows after its confirm button (a bank window, a one-time code, a signature). The agent
  hands any such window to the accountant and then proves the result from the list of orders.
