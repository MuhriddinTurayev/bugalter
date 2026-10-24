import { PORTAL_ORIGIN } from './config.js';
import { dismissNotice, settle } from './discover.js';
import { isLoggedIn } from './login.js';
import { parseMinor } from './money.js';

// The payment orders of an individual entrepreneur at my.soliq.uz, observed 2026-10-24 (read only):
//   /remotes-services/tax-payment/pay?interactiveId=635 lists them, newest first, one Ant Design table:
//   [actions] | "№ 18 24.10.2026" | tax code (100, 38, 47, 199) | amount (so'm) | status | payer bank MFO | payer account |
//   receiver (treasury) account | year;  a "Йил" dropdown (2026, 2025, ...) and a page-size dropdown (10, 15, 20).
// Status "Банк томонидан тўланган тўлов топшириқномалар" means the bank has paid the order.
// Opening the list pays and changes nothing. The "new payment order" button is never touched here.
export const PAYMENTS_PATH = '/remotes-services/tax-payment/pay?interactiveId=635';

const ORDER = /^№\s*(\d+)\s+(\d{2})\.(\d{2})\.(\d{4})/;

/** The tax's category in Bugalter, from the cabinet's tax code. Unknown codes stay "other" and keep their code. */
export const TAX_OF_CODE = { 100: 'turnover', 38: 'social', 47: 'fixed', 199: 'other' };

/** "№ 18 24.10.2026" -> {number, date}; null when the cell is not an order reference. */
export function parseOrderCell(text) {
  const m = ORDER.exec(String(text).replace(/\s+/g, ' ').trim());
  return m ? { number: m[1], date: `${m[4]}-${m[3]}-${m[2]}` } : null;
}

/** One body row (its cells' text) -> a payment. A row that does not look like one throws: a changed table stops the task. */
export function toPayment(cells) {
  const at = cells.findIndex((c) => ORDER.test(String(c).replace(/\s+/g, ' ').trim()));
  if (at < 0) throw new Error('A row of the payment orders table has no order number and date. The page changed; stopped.');

  const order = parseOrderCell(cells[at]);
  const [taxCode, amountText, status, payerBank, payerAccount, receiverAccount, year] = cells.slice(at + 1, at + 8).map((c) => String(c ?? '').replace(/\s+/g, ' ').trim());
  const amount = parseMinor(amountText);

  if (!/^\d{1,4}$/.test(taxCode) || amount === null || amount < 0) {
    throw new Error(`The payment order ${order.number} of ${order.date} could not be read (tax "${taxCode.slice(0, 8)}", amount "${amountText.slice(0, 20)}"). Stopped instead of guessing.`);
  }

  return {
    number: order.number,
    date: order.date,
    tax_code: taxCode,
    tax: TAX_OF_CODE[taxCode] ?? 'other',
    amount_minor: amount,
    status_text: status.slice(0, 160),
    paid: /тўланган/i.test(status) && !/тўланмаган/i.test(status), // "paid by the bank"; anything else is not shown as paid
    payer_bank_code: payerBank.slice(0, 12),
    payer_account: payerAccount.replace(/\D/g, '').slice(0, 20),
    receiver_account: receiverAccount.replace(/\D/g, '').slice(0, 20),
    year: /^\d{4}$/.test(year) ? year : null,
  };
}

/** Every row of the table that is open now, across its pages. */
export async function parsePayments(page, { maxPages = 30 } = {}) {
  const table = page.locator('.ant-table').first();
  await table.waitFor({ state: 'attached', timeout: 20_000 });
  await page.locator('.ant-spin-spinning').first().waitFor({ state: 'detached', timeout: 20_000 }).catch(() => {});
  await page.waitForTimeout(1200);

  const payments = [];
  const seen = new Set();

  for (let n = 0; n < maxPages; n++) {
    for (const tr of await table.locator('tbody tr:not(.ant-table-placeholder):not(.ant-table-measure-row)').all()) {
      const cells = await tr.locator('td').allInnerTexts();
      if (cells.every((c) => c.trim() === '') || /маълумот мавжуд эмас|no data/i.test(cells.join(' '))) continue;

      const payment = toPayment(cells);
      const key = `${payment.number}|${payment.date}|${payment.tax_code}|${payment.amount_minor}`;
      if (!seen.has(key)) { seen.add(key); payments.push(payment); }
    }

    const next = page.locator('.ant-pagination-next:not(.ant-pagination-disabled)').first();
    if (!(await next.count())) break;
    await next.click({ timeout: 4000 });
    await page.waitForTimeout(1200);
  }

  return payments;
}

async function open(page) {
  await page.goto(`${PORTAL_ORIGIN}${PAYMENTS_PATH}`, { waitUntil: 'domcontentloaded', timeout: 45_000 });
  await settle(page);
  await dismissNotice(page);
  if (!isLoggedIn(page.url())) throw new Error('The session ended while reading the payments.');
}

/** The payment orders of this year and the year before. Each payment says what the cabinet says; nothing is invented. */
export async function readPayments(page, { years = 2, now = new Date() } = {}) {
  await open(page);

  const payments = [];
  for (let k = 0; k < years; k++) {
    const year = String(now.getFullYear() - k);
    if (k > 0) {
      // choose the previous year in the page's own "Йил" dropdown; a year the list does not offer is simply not read
      const select = page.locator('.ant-select').first();
      await select.locator('.ant-select-selector').click({ timeout: 4000 });
      await page.waitForTimeout(500);
      const option = page.locator('.ant-select-dropdown:visible .ant-select-item-option', { hasText: year }).first();
      if (!(await option.count())) { await page.keyboard.press('Escape'); break; }
      await option.click({ timeout: 4000 });
      await page.waitForTimeout(2500);
    }
    payments.push(...await parsePayments(page));
  }

  return payments;
}
