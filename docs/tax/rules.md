# Tax rules

Source of truth for the tax rules tables. `TaxRuleSeeder` reads [rules.json](rules.json) and writes the central
tables `tax_rules` and `tax_deadlines`. The platform panel (Tax rules) shows them, lets a super admin correct a value
and marks a rule **verified**.

> **Status: needs verification.** Every value below was filled in from the Tax Code of the Republic of Uzbekistan
> (2020 edition with amendments) as known when it was written. An accountant must check each one against the law in
> force and mark it verified in the panel. Until then:
>
> - calculations work and are shown with a "not verified" badge;
> - a person may still approve and submit a report (they see the warning);
> - **autopilot and mandate submissions are blocked** by the `rules_verified` gate.

## Rates

| Code | Value | Effective from | Source |
|---|---|---|---|
| `vat.standard` | 12% | 2023-01-01 | Tax Code, art. 258 |
| `vat.zero` | 0% (export) | 2020-01-01 | Tax Code, art. 259 |
| `vat.registration_threshold` | 1 000 000 000 soʻm a year | 2020-01-01 | Tax Code, art. 462 |
| `turnover.general` | 4% | 2020-01-01 | Tax Code, art. 467 |
| `profit.general` | 15% | 2019-01-01 | Tax Code, art. 337 |
| `pit.standard` | 12% | 2019-01-01 | Tax Code, art. 381 |
| `inps.employee` | 0.1% (withheld from the PIT amount) | 2020-01-01 | Law on funded pensions |
| `social.general` | 12% | 2019-01-01 | Tax Code, art. 405 |
| `social.budget` | 25% (budget organisations) | 2019-01-01 | Tax Code, art. 405 |
| `dividend.resident` | 5% | 2019-01-01 | Tax Code, art. 337 |

## Deadlines

| Report | Period | Due |
|---|---|---|
| VAT (`vat`) | month | 20th day of the following month |
| Turnover tax (`turnover`) | quarter | 15th day of the month after the quarter |
| Profit tax (`profit`) | quarter (year to date) | 20th day of the month after the quarter |
| PIT and social tax (`payroll`) | month | 15th day of the following month |

## How the calculators use them

All calculators are deterministic PHP (`app/Tax`), work in integer tiyin and round each line half up.

- **VAT:** output VAT of outgoing invoices/acts (sent or paid) issued in the month, plus VAT contained in sales booked
  without a document (`amount × rate / (100% + rate)`), minus input VAT of incoming documents. A negative result is
  shown as a credit and nothing is payable.
- **Turnover tax:** revenue of the quarter (outgoing documents without VAT plus sales without a document) × rate.
- **Profit tax:** year to date. Taxable profit = revenue without VAT − deductible expenses (incoming documents without
  VAT, expenses without a document except tax payments, social tax of approved payroll). Payable for the quarter =
  tax on the profit to the end of the quarter − tax on the profit to the end of the previous quarter.
- **Payroll:** per employee: PIT = gross × PIT rate, INPS = gross × INPS rate (part of PIT), PIT to the budget =
  PIT − INPS, net pay = gross − PIT, social tax = gross × social rate (budget rate for budget organisations).

## Changing a rule

Never change a rate in code. Add a new row to `rules.json` with a new `effective_from` (and close the old one with
`effective_to`), run `php artisan tax:rules-sync`, then verify it in the panel. Reports keep the rule versions they
were calculated with.
