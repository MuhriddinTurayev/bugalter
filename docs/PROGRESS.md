# Progress

This checklist tracks the prompts in [PLAN.md](PLAN.md), in the order they must be run. Tick an item once it is finished and `php artisan test`, `vendor/bin/pint` and `npm run build` pass.

Original prompts 16, 18, 20 and 22 are replaced by U3, U6, U8 and U9. Do not run them.

- [x] Prompt 0
- [x] Prompt 1
- [x] Prompt 2
- [ ] U1
- [~] Prompt 3 (basic form: users with roles, login, layout; roles still plain enum, no spatie yet)
- [~] Prompt 4 (minimal: `stancl/tenancy`, database per company on subdomains, `CompanyContext` middleware; no tenant-aware queues, storage or billing)
- [~] Prompt 5 (minimal: `TenantProvisioningService` creates the database, domain, profile and invited owner; no automated deletion or data export)
- [x] Platform admin panel (PLAN section 8): own guard and table, mandatory TOTP 2FA, companies, plans, staff, audit log, support access, kill switch; see README
- [ ] Prompt 6
- [ ] Prompt 7
- [ ] U2
- [ ] Prompt 8
- [ ] Prompt 9
- [~] Prompt 10 (basic form: contractors with import/export; no STIR lookup, no VAT/e-invoice sync)
- [~] Prompt 11 (basic form: invoices/acts, race-safe numbering, PDF; no e-invoice, no templates/edit of numbering)
- [~] Prompt 12 (basic form: accounts, transactions, categories, CSV import; no reconciliation or bank API)
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

`[~]` means done in its basic form; what remains is noted on the line. Prompt 1 is only partly done: Fortify and spatie/laravel-permission are
not installed yet (Pest, Larastan, dompdf, Excel and brick/money are).
Multi-tenancy (Prompts 4-5) is not built: the app runs in single company dev mode (`SINGLE_COMPANY=true`).
