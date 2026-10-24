import assert from 'node:assert/strict';
import { test } from 'node:test';
import { chromium } from 'playwright-core';
import { amountMinor, taxCategory } from '../src/balances.js';
import { findBrowser } from '../src/browser.js';
import { rowsOf } from './support.js';

// The "Менинг солиқларим" table as observed: Ant Design, header cells in thead, one row per tax.
const HEAD = ['Солиқ номи', 'Солиқ коди', 'Солиқ қарздорлиги', 'Ортиқча тўлов', 'Жорий йилда ҳисобланган солиқ суммаси', 'Тўлов учун умумий сумма'];
const table = (rows, head = HEAD) => `<div class="ant-table"><table><thead><tr>${head.map((h) => `<th>${h}</th>`).join('')}</tr></thead><tbody>${rows.map((r) => `<tr>${r.map((c) => `<td>${c}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`;

async function read(html) {
  const browser = await chromium.launch({ channel: findBrowser(), headless: true });
  try {
    const page = await browser.newPage();
    await page.setContent(html);
    return await rowsOf(page);
  } finally {
    await browser.close();
  }
}

test('debt, overpayment and assessment are read per tax, in whole tiyin', async () => {
  const rows = await read(table([
    ['Мол-мулк солиғи', '4400', '1 250 000,50', '0', '300 000', '1 550 000,50'],
    ['Ер солиғи', '4500', '', '20 000', '', ''],
  ]));

  assert.deepEqual(rows, [
    { tax: 'property', name: 'Мол-мулк солиғи', debt_minor: 125_000_050, overpaid_minor: 0, penalty_minor: 0, assessed_minor: 30_000_000, total_minor: 155_000_050 },
    { tax: 'land', name: 'Ер солиғи', debt_minor: 0, overpaid_minor: 2_000_000, penalty_minor: 0, assessed_minor: 0, total_minor: 0 },
  ]);
});

test('"no data" and an empty table are an empty list, not a zero tax', async () => {
  assert.deepEqual(await read(table([])), []);
  assert.deepEqual(await read(table([['Маълумот мавжуд эмас']])), []);
});

test('a sticky-header duplicate of the same row is counted once', async () => {
  const one = ['Айланма солиғи', '4200', '500', '0', '0', '500'];
  assert.equal((await read(table([one]) + table([one]))).length, 1);
});

test('an amount that cannot be read stops the task instead of becoming zero', async () => {
  await assert.rejects(read(table([['Мол-мулк солиғи', '4400', 'abc', '0', '0', '0']])), /cannot be read as money/);
});

test('a table that lost a column stops the task', async () => {
  await assert.rejects(read(table([['Мол-мулк солиғи', '1']], ['Солиқ номи', 'Солиқ коди'])), /no "debt" column/);
});

test('amounts and categories', () => {
  assert.equal(amountMinor('', 'x'), 0);
  assert.equal(amountMinor('1 234,56', 'x'), 123_456);
  assert.throws(() => amountMinor('12,345,67,8', 'x'));
  assert.equal(taxCategory('Айланмадан олинадиган солиқ'), 'turnover');
  assert.equal(taxCategory('Қўшилган қиймат солиғи'), 'vat');
  assert.equal(taxCategory('Номаълум солиқ тури'), 'other'); // unknown wording keeps its own name, never a guessed category
});
