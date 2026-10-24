import { datesIn } from './cabinet.js';

/**
 * Reads an Ant Design table by its HEADER TEXT, never by position or generated class names: the column a value
 * belongs to is the one whose <th> says so. A required column that is missing throws, so a changed table stops the
 * task instead of reading the wrong column.
 *
 * @param {import('playwright-core').Page} page
 * @param {{ table?: string, columns: Record<string, RegExp> }} spec  logical name -> header pattern
 * @returns {Promise<Array<Record<string, string>>>} one object per body row (text as shown)
 */
export async function readTable(page, { table = '.ant-table', columns }) {
  const root = page.locator(table).first();
  // "attached", not "visible": a journal pane that is not the open tab is in the DOM but hidden, and is still readable.
  await root.waitFor({ state: 'attached', timeout: 20_000 });

  const headers = (await root.locator('thead th').allInnerTexts()).map((h) => h.trim());
  const index = {};
  for (const [name, pattern] of Object.entries(columns)) {
    const i = headers.findIndex((h) => pattern.test(h));
    if (i < 0) throw new Error(`The table has no "${name}" column (headers: ${headers.filter(Boolean).join(' | ')}). The page changed; stopped.`);
    index[name] = i;
  }

  const rows = [];
  for (const tr of await root.locator('tbody tr:not(.ant-table-placeholder):not(.ant-table-measure-row)').all()) {
    const cells = (await tr.locator('td').allInnerTexts()).map((c) => c.trim());
    if (cells.length < headers.length - 2) continue; // placeholder / expanded rows
    rows.push(Object.fromEntries(Object.entries(index).map(([name, i]) => [name, cells[i] ?? ''])));
  }
  return rows;
}

/** A declaration history row, as the cabinet shows it. Statuses are mapped only by the words in `statusWords`. */
export function toHistoryRow(row, statusWords = {}) {
  const [submitted] = datesIn(row.submitted_at ?? '');
  const text = (row.status ?? '').toLowerCase();
  const known = Object.entries(statusWords).find(([, words]) => words.some((w) => text.includes(String(w).toLowerCase())))?.[0] ?? null;
  return { id: row.id ?? '', year: /^\d{4}$/.test(row.year ?? '') ? row.year : null, kind: row.kind ?? '', submitted_at: submitted ?? null, status_text: row.status ?? '', status: known };
}
