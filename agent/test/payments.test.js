import assert from 'node:assert/strict';
import { test } from 'node:test';
import { chromium } from 'playwright-core';
import { findBrowser } from '../src/browser.js';
import { parseOrderCell, parsePayments, toPayment } from '../src/payments.js';

// The rows of the payment orders list as observed (the amounts are in so'm, the dates dd.mm.yyyy).
const row = (number, date, tax, amount, status = 'Банк томонидан тўланган тўлов топшириқномалар') => ['', `№ ${number} ${date}`, tax, amount, status, '00083', '20218000907449368001', '23402000300100001010', '2026'];

test('an order cell is split into number and ISO date', () => {
  assert.deepEqual(parseOrderCell('№ 18 24.10.2026'), { number: '18', date: '2026-10-24' });
  assert.equal(parseOrderCell('Сумма'), null);
});

test('a payment is read in whole tiyin with its tax code, status and accounts', () => {
  const p = toPayment(row('16', '23.10.2026', '38', '2 000 000'));
  assert.equal(p.amount_minor, 200_000_000);
  assert.equal(p.tax_code, '38');
  assert.equal(p.paid, true);
  assert.equal(p.date, '2026-10-23');
  assert.equal(p.payer_account, '20218000907449368001');

  assert.equal(toPayment(row('1', '01.01.2026', '100', '1 000', 'Тўланмаган')).paid, false); // never shown as paid by guess
  assert.equal(toPayment(row('2', '01.01.2026', '999', '5')).tax, 'other');
});

test('a row that is not an order, or has an unreadable amount, stops the read', () => {
  assert.throws(() => toPayment(['x', 'y']), /no order number/);
  assert.throws(() => toPayment(row('3', '01.01.2026', '100', 'abc')), /Stopped instead of guessing/);
  assert.throws(() => toPayment(row('3', '01.01.2026', 'XY', '1 000')), /could not be read/);
});

test('the table is read from the page, once per row', async () => {
  const html = '<div class="ant-table"><table><thead><tr><th>Амаллар Тўлов топшириқнома</th></tr></thead><tbody>'
    + [row('18', '24.10.2026', '100', '1 000'), row('16', '23.10.2026', '38', '2 000 000'), row('18', '24.10.2026', '100', '1 000')]
      .map((r) => `<tr>${r.map((c) => `<td>${c}</td>`).join('')}</tr>`).join('')
    + '</tbody></table></div>';

  const browser = await chromium.launch({ channel: findBrowser(), headless: true });
  try {
    const page = await browser.newPage();
    await page.setContent(html);
    const payments = await parsePayments(page);
    assert.equal(payments.length, 2);
    assert.deepEqual(payments.map((p) => p.amount_minor), [100_000, 200_000_000]);
  } finally {
    await browser.close();
  }
});
