import assert from 'node:assert/strict';
import { test } from 'node:test';
import { chromium } from 'playwright-core';
import { readTable, toHistoryRow } from '../src/tables.js';
import { findBrowser } from '../src/browser.js';

// A real browser on a local HTML page shaped like the cabinet's Ant Design table (headers as observed on
// /remotes-services/declaration/history). No network, no login.
const HTML = (headers, rows) => `<div class="ant-table"><table><thead><tr>${headers.map((h) => `<th>${h}</th>`).join('')}</tr></thead>
<tbody>${rows.map((r) => `<tr>${r.map((c) => `<td>${c}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`;

const HEADERS = ['ID рақами', 'Ҳудуд номи', 'Йил', 'Ҳисобот тури', 'Юборилган сана', 'Ҳолати', 'Декларация бланкаси', 'Ҳозир тўлаш'];
const COLUMNS = { id: /ID/, year: /^Йил$/, kind: /Ҳисобот тури/, submitted_at: /Юборилган сана/, status: /Ҳолати/ };

async function withPage(html, fn) {
  const browser = await chromium.launch({ channel: findBrowser(), headless: true });
  try {
    const page = await browser.newPage();
    await page.setContent(html);
    return await fn(page);
  } finally {
    await browser.close();
  }
}

test('columns are found by their header text, not by position', async () => {
  const reordered = ['Ҳолати', ...HEADERS.filter((h) => h !== 'Ҳолати')];
  const rows = await withPage(HTML(reordered, [['Қабул қилинди', '1001', 'X', '2025', 'Бирламчи', '10.02.2026', '', '']]), (p) => readTable(p, { columns: COLUMNS }));
  assert.deepEqual(rows, [{ id: '1001', year: '2025', kind: 'Бирламчи', submitted_at: '10.02.2026', status: 'Қабул қилинди' }]);
});

test('a table that lost a required column stops the task instead of reading the wrong one', async () => {
  const html = HTML(HEADERS.filter((h) => h !== 'Ҳолати'), [['1', 'x', '2025', 'a', '10.02.2026', '', '']]);
  await assert.rejects(withPage(html, (p) => readTable(p, { columns: COLUMNS })), /no "status" column/);
});

test('an empty table is an empty list, not an error', async () => {
  assert.deepEqual(await withPage(HTML(HEADERS, []), (p) => readTable(p, { columns: COLUMNS })), []);
});

test('unknown status wording is never mapped to a known status', () => {
  const words = { accepted: ['қабул'], rejected: ['рад'] };
  assert.equal(toHistoryRow({ status: 'Қабул қилинди', year: '2025', submitted_at: '10.02.2026' }, words).status, 'accepted');
  assert.equal(toHistoryRow({ status: 'Текширилмоқда' }, words).status, null);
  assert.equal(toHistoryRow({ year: '20x5' }).year, null);
});
