# Progress

This checklist tracks the prompts in [PLAN.md](PLAN.md), in the order they must be run. Tick an item once it is finished and `php artisan test`, `vendor/bin/pint` and `npm run build` pass.

Original prompts 16, 18, 20 and 22 are replaced by U3, U6, U8 and U9. Do not run them.

- [x] Prompt 0
- [x] Prompt 1
- [x] Prompt 2
- [ ] U1
- [~] Prompt 3 (users with roles, login, layout; the owner invites people, changes roles, grants extra permissions; no Fortify 2FA for company users yet)
- [~] Prompt 4 (`stancl/tenancy`, database per company on subdomains, `CompanyContext`, a cache prefix per company; no tenant-aware queues or billing)
- [~] Prompt 5 (`TenantProvisioningService` creates the database, domain, profile and invited owner; no automated deletion or data export)
- [x] Platform admin panel (PLAN section 8): own guard and table, mandatory TOTP 2FA, companies, plans, staff, audit log, support access, kill switch; see README
- [ ] Prompt 6
- [ ] Prompt 7
- [ ] U2
- [ ] Prompt 8
- [ ] Prompt 9
- [~] Prompt 10 (contractors with import/export; no STIR lookup, no VAT/e-invoice sync)
- [~] Prompt 11 (invoices/acts, race-safe and gap-free numbering, PDF; no e-invoice, no templates/edit of numbering)
- [~] Prompt 12 (accounts, transactions, categories, CSV import; no reconciliation or bank API)
- [ ] Prompt 13
- [ ] Prompt 14
- [ ] Prompt 15
- [ ] U3 (replaces Prompt 16)
- [ ] U4
- [ ] U5
- [ ] Prompt 17
- [ ] U6 (replaces Prompt 18)
- [ ] Prompt 19
- [ ] U7
- [ ] U8 (replaces Prompt 20)
- [ ] Prompt 21
- [ ] U9 (replaces Prompt 22)
- [ ] U10
- [ ] Prompt 23
- [ ] Prompt 24
- [ ] Prompt 25
- [ ] U11
- [ ] Prompt 26

`[~]` means done in its basic form; what remains is noted on the line. The prompt texts for 6-26 and U1-U11 are not in the repository,
so the work below is listed by PLAN section instead of being ticked against a prompt number.

## Built from the PLAN sections (product-direction update)

- [x] **Tax rules (sections 5, 9):** `docs/tax/rules.json` → central `tax_rules` / `tax_deadlines`, versioned; `php artisan tax:rules-sync`;
  the platform panel (Tax rules) corrects and verifies them. All values are marked *needs verification* until an accountant checks them.
- [x] **Deterministic calculators:** VAT, turnover tax, profit tax (year to date), PIT/INPS/social tax (`app/Tax`), integer tiyin, half-up rounding,
  data-quality issues, rule versions stored with every report; tax calendar from the regime and the deadline rules.
- [x] **Payroll:** employees CRUD, monthly payroll (draft → approved → paid), `payroll.approve`.
- [x] **Submission flow (section 5):** approval request, per-report approval or mandate, cancel window, hard-stop gates, signer, gateway
  (sandbox or configurable HTTP), signature log, company and platform kill switch, autopilot levels 0-3.
- [x] **Signing vault (section 9):** envelope encryption with `SIGNING_MASTER_KEY`, key expiry warnings (30/14/3 days), expiry drops level 3 to 1,
  DeviceSigner (owner signs the hash with E-IMZO and pastes the PKCS#7).
- [x] **AI accountant (section 6):** ChatGPT, Claude (official Anthropic SDK), Gemini, DeepSeek; platform keys and an optional company key, both
  encrypted; tools only over deterministic code; proposals with owner-controlled auto-apply; no tool to sign, manage keys, mandates, autopilot or roles.
- [x] **Permissions (section 3):** `reports.approve`, `reports.submit`, `signing.keys.manage`, `autopilot.manage`, `payroll.approve`; role defaults
  plus owner grants (`user_permissions`). Implemented on the role enum instead of `spatie/laravel-permission`.
- [x] **Plan limits and subscription:** users, documents and transactions per month enforced; an ended subscription makes a company read-only.
- [x] **Company audit log:** every business change and every AI/autopilot action with its actor.
- [x] **Automatic report preparation and safe submission ([SUBMISSION.md](SUBMISSION.md)):** "Hisobotlarni avtomatik tayyorlash"
  (`/tax/runs/new`: month → queued analysis with real step states → checklist review → batch approval bound to the reviewed
  version hashes with a declaration → per-report submission jobs). `report_approvals` (invalidate-only), `submission_attempts`
  (written before sending, idempotency key), statuses `submitting` and `unknown` (never resent), `SubmissionVerifier` (hourly
  verification through the gateway or a later cabinet visit, recovery of interrupted deliveries, manual reconciliation with an
  audited note). HTTP gateway: timeouts/5xx/409/429 are unknown, not rejected; optional status path.
  Not done: an official submission API or operator agreement, the cabinet robot service, a legally valid server-side signer.
- [x] **Tax cabinet (my.soliq.uz) without an API:** `App\Portal` signs in with the vault key (owner consent `portal_access`, login
  signatures logged with purpose `login`), reads the cabinet (balances, filed reports, messages), compares it with the books
  (`PortalAnalyzer`), prepares missing/overdue/rejected reports and asks for approval; page `/tax/portal`, daily `bugalter:portal-check`,
  AI tool `get_tax_portal_status` (read-only). One-click "Submit?" → "Yes" (`approveAndSubmit`, needs approve + submit). Gateway `portal`
  signs the cabinet's own document only after `AuthorityDocumentCheck`; one submission per report at a time; never refiles a report the
  cabinet already shows. Drivers: `sandbox` (no network) and `robot` (HTTP protocol in `RobotPortal`).
  Not done: the robot service itself (browser side), the per-form mapping of the cabinet, and a real E-IMZO signer.
- [x] **Scheduler:** `bugalter:autopilot` hourly, `bugalter:daily` at 08:00 (needs `php artisan schedule:run` every minute in production).

Not done: a real tax authority or e-reporting operator integration (needs an agreement, API documentation and credentials; the HTTP gateway
is the adapter point), a real E-IMZO signing server, Fortify 2FA for company users, `spatie/laravel-permission`.
- [~] **Desktop agent (`agent/`, gateway `desktop`):** Windows Node.js agent driving a visible Edge/Chrome on my.soliq.uz with the
  official E-IMZO app; Laravel queue and API (`agent_tasks`, `desktop_agents`, token per computer), page Tax → Agent, form check
  before signing, unknown-outcome handling, cabinet reads normalised like the robot's. Verified live: the E-IMZO WebSocket and the
  login page's key cards. **Not done:** the cabinet pages behind the login (reports list, balances, the report forms): selectors
  in `agent/cabinet-map.json` stay empty and unverified until recorded from a signed-in session (`npm run inspect`); no real
  filing was performed or tested. See [agent/README.md](../agent/README.md) and [SUBMISSION.md](SUBMISSION.md).
  Update 2026-10-24: the cabinet read now covers the journal, every tax balance ("Менинг солиқларим": debt, overpayment,
  assessment per tax), the tax authority's letters, and trust-on-first-use identity linking. A report for a period with no
  documents, money movements or payroll is refused (`no_books`, never an invented "0 so'm"). **Final confirmation:** after the
  agent has filled the real cabinet form (nothing signed), the panel shows the values it read and asks "Topshirilsinmi?"
  (`final_confirmation`); only "yes" lets the form check, signing and sending run, "no" withdraws the approval. Still **not
  done:** filling the cells (dialog-edited), the sign/send flow and the result screen, which need the open period (observed
  forms are blank); payments; legal-entity entry.
  Update 2026-10-24 (evening): the turnover form of an individual entrepreneur was read live (period September): the cabinet
  calculates the whole report itself. Reports with `source = cabinet` are prepared from those figures, checked
  (arithmetic, period, rate vs rule table), approved by a person only, and sent through the cabinet's own «Отправить» after the
  panel's final "Topshirilsinmi?". Outcome from the journal. The send flow itself has not been run (first real filing needs the
  owner's explicit go-ahead).
  Update 2026-10-24 (night): the agent now works in the entrepreneur's own cabinet; reads the personal tax card (debt, prepayment,
  penalty per tax), the demands of the tax authority (5 unanswered debt demands found), the payment orders, and pays taxes from the
  platform (Tax → Payments) with approval bound to tax/type/amount, a check without paying, and unknown-outcome settlement. The
  payment path was run live in "check without paying" mode only; the first real payment, and what follows the cabinet's confirm
  button, are still unobserved.
