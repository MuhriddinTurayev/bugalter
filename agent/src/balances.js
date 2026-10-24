import { PORTAL_ORIGIN } from './config.js';
import { dismissNotice, settle } from './discover.js';
import { isLoggedIn } from './login.js';
import { parseMinor } from './money.js';

// Pages observed on my.soliq.uz (individual / entrepreneur cabinet, 2026-10-24), read-only:
//  - /remotes-services/tax-payment/payment_individual  "Менинг солиқларим": one row per tax, columns
//      Солиқ номи | Солиқ коди | Солиқ қарздорлиги | Ортиқча тўлов | Жорий йилда ҳисобланган солиқ суммаси | Тўлов учун умумий сумма
//  - /interactive/shared-services/all-messages/claim/{requirement,notice}  "Солиқ органлари билан муносабатлар":
//      Ҳужжат тури | Рақами | Рўйхатдан ўтказиш санаси | (Жавоб бериш учун охирги муддат) | Амаллар
// Opening these pages pays and changes nothing. The "pay" buttons on them are never touched.
export const BALANCES_PATH = '/remotes-services/tax-payment/payment_individual';
export const NOTICE_PATHS = { requirement: '/interactive/shared-services/all-messages/claim/requirement', notice: '/interactive/shared-services/all-messages/claim/notice' };

const BALANCE_COLUMNS = {
  name: /Солиқ номи/i,
  code: /Солиқ коди/i,
  debt: /Солиқ қарздорлиги/i,
  overpaid: /Ортиқча тўлов/i,
  assessed: /ҳисобланган солиқ суммаси/i,
  total: /Тўлов учун умумий сумма/i,
};

/** The tax's category for Bugalter, from stems of its name; unknown names stay "other" and keep their own words. */
export function taxCategory(name) {
  const n = String(name).toLowerCase();
  const stems = [
    ['vat', /қўшилган қиймат|ққс|ндс/],
    ['turnover', /айланма|оборот/],
    ['profit', /фойда|прибыл/],
    ['pit', /жисмоний шахслардан олинадиган даромад|жфдс|ндфл|даромад солиғ/],
    ['social', /ижтимоий солиқ|социальн/],
    ['property', /мол-мулк|имуществ/],
    ['land', /ер солиғи|земельн/],
    ['water', /сув ресурс|водн/],
  ];

  return stems.find(([, re]) => re.test(n))?.[0] ?? 'other';
}

/** "" -> 0 (an empty cell on this page is a zero balance); an amount that cannot be read is an error, never a zero. */
export function amountMinor(text, what) {
  const t = String(text ?? '').trim();
  if (t === '' || t === '-' || t === '—') return 0;

  const minor = parseMinor(t);
  if (minor === null) throw new Error(`The amount "${t.slice(0, 20)}" in column "${what}" cannot be read as money. Stopped instead of guessing.`);

  return minor;
}

async function open(page, path) {
  await page.goto(`${PORTAL_ORIGIN}${path}`, { waitUntil: 'domcontentloaded', timeout: 45_000 });
  await settle(page);
  await dismissNotice(page);
  if (!isLoggedIn(page.url())) throw new Error('The session ended while reading the cabinet.');
}

/** Every body row of every table on the page, keyed by the header texts; identical duplicate rows (sticky headers) once. */
async function rowsByHeader(page, columns) {
  const tables = page.locator('.ant-table');
  await tables.first().waitFor({ state: 'attached', timeout: 20_000 });
  // An empty table that is still loading must not be read as "no debt": wait until the spinner is gone.
  await page.locator('.ant-spin-spinning').first().waitFor({ state: 'detached', timeout: 20_000 }).catch(() => {});
  await page.waitForTimeout(1500);

  const headers = (await tables.first().locator('thead th').allInnerTexts()).map((h) => h.replace(/\s+/g, ' ').trim());
  const index = {};
  for (const [name, pattern] of Object.entries(columns)) {
    const i = headers.findIndex((h) => pattern.test(h));
    if (i >= 0) index[name] = i;
  }

  const seen = new Set();
  const rows = [];
  for (const tr of await page.locator('.ant-table tbody tr:not(.ant-table-placeholder):not(.ant-table-measure-row)').all()) {
    const cells = (await tr.locator('td').allInnerTexts()).map((c) => c.replace(/\s+/g, ' ').trim());
    if (cells.length < 2 || cells.every((c) => c === '') || /маълумот мавжуд эмас|нет данных|no data/i.test(cells.join(' '))) continue;

    const key = cells.join('|');
    if (seen.has(key)) continue;
    seen.add(key);
    rows.push({ headers, index, cells });
  }

  return rows;
}

/** Debt, overpayment and this year's assessment for every tax the cabinet lists. Amounts in whole tiyin. */
export async function readBalances(page) {
  await open(page, BALANCES_PATH);

  return parseBalances(page);
}

/** The table of the page that is open now (separate from navigation, so it can be tested on a page of known markup). */
export async function parseBalances(page) {
  const rows = await rowsByHeader(page, BALANCE_COLUMNS);

  for (const required of ['name', 'debt', 'overpaid']) {
    if (rows[0] && rows[0].index[required] === undefined) throw new Error(`The tax table has no "${required}" column any more. The page changed; stopped.`);
  }

  return rows.map(({ index, cells }) => {
    const cell = (k) => (index[k] === undefined ? '' : cells[index[k]] ?? '');
    const name = cell('name').slice(0, 120);

    return {
      tax: taxCategory(name),
      name,
      debt_minor: amountMinor(cell('debt'), 'Солиқ қарздорлиги'),
      overpaid_minor: amountMinor(cell('overpaid'), 'Ортиқча тўлов'),
      penalty_minor: 0, // the table has no penalty column; never invented
      assessed_minor: amountMinor(cell('assessed'), 'ҳисобланган'),
      total_minor: amountMinor(cell('total'), 'умумий сумма'),
    };
  });
}

const NOTICE_COLUMNS = { type: /Ҳужжат тури/i, number: /Рақами/i, date: /Рўйхатдан ўтказиш санаси/i, deadline: /охирги муддат/i };
const dateOf = (t) => {
  const m = /(\d{2})\.(\d{2})\.(\d{4})/.exec(String(t));
  return m ? `${m[3]}-${m[2]}-${m[1]}` : null;
};

/** Requirements (talabnoma) and notices (xabarnoma) from the tax authority, as the cabinet lists them. */
export async function readNotices(page) {
  const messages = [];

  for (const [kind, path] of Object.entries(NOTICE_PATHS)) {
    await open(page, path);
    for (const { index, cells } of await rowsByHeader(page, NOTICE_COLUMNS)) {
      const cell = (k) => (index[k] === undefined ? '' : cells[index[k]] ?? '');
      const deadline = dateOf(cell('deadline'));
      const unreadByCabinet = /Ўқилмаган|Oqilmagan|Не прочитан/i.test(cell('date'));
      const unanswered = kind === 'requirement' && /Жавоб берилмаган|Javob berilmagan|Не отвечен/i.test(cell('deadline'));

      messages.push({
        date: dateOf(cell('date')),
        title: [kind === 'requirement' ? 'Talabnoma' : 'Xabarnoma', cell('type'), cell('number') ? `№${cell('number')}` : '', deadline ? `(javob: ${deadline})` : ''].filter(Boolean).join(' ').slice(0, 200),
        // What the cabinet marks unread, and a demand that nobody has answered yet: both need the owner's eyes.
        unread: unreadByCabinet || unanswered,
      });
    }
  }

  return messages;
}
