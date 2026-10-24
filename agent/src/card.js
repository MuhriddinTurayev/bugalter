import { PORTAL_ORIGIN } from './config.js';
import { dismissNotice, settle } from './discover.js';
import { isLoggedIn } from './login.js';
import { parseMinor } from './money.js';
import { plain } from './pay.js';

// The personal tax card of an entrepreneur ("Солиштирма далолатнома ва шахсий ҳисобварақлар", the KLS service), observed
// 2026-10-24 in the entrepreneur's cabinet: filters region, district, year, tax type, unit; "Кўрсатиш" fills a table of
// operations of that tax (accruals, payments, returns, penalties), with a "Сальдо на DD.MM.YYYY" row and the running tax debt,
// overpayment and unpaid penalty. Rows dated AFTER today are the cabinet's own forecast (future accruals and penalty) and are
// never counted. Opening the page and asking to show it pays and changes nothing.
export const CARD_PATH = '/remotes-services/kls/individual-kls';

const COLUMN = {
  date: /Операция санаси/i,
  content: /Операция мазмуни/i,
  accrued: /Тўлашга ҳисобланган сумма/i,
  paid: /^Тўланган сумма/i,
  debt: /Солиқ қарзи/i,
  overpaid: /Ортиқча тўлов/i,
  penalty: /Тўланмаган устама қолдиғи/i,
};

const SAFE = (s) => String(s ?? '').replace(/\s+/g, ' ').trim();
const dateOf = (t) => {
  const m = /(\d{2})\.(\d{2})\.(\d{4})/.exec(String(t));
  return m ? `${m[3]}-${m[2]}-${m[1]}` : null;
};
/** An empty cell is zero; text that is not money stops the read (never guessed). */
const money = (t, what) => {
  const text = SAFE(t);
  if (text === '' || text === '-') return 0;
  const minor = parseMinor(text);
  if (minor === null) throw new Error(`The card amount "${text.slice(0, 20)}" (${what}) cannot be read as money. Stopped instead of guessing.`);
  return minor;
};

/** The tax's category from its cabinet code (the first digits of the tax type's name). */
export const CARD_TAX = { 38: 'social', 47: 'fixed', 100: 'turnover', 199: 'other' };

/**
 * The standing of one tax on `today`, from its card table: the latest operation dated today or earlier (the later of two on the
 * same day wins), with the accrued total of the latest balance row. Returns null for an empty table.
 *
 * @param {{headers: string[], rows: string[][]}} table
 * @param {string} today  Y-m-d
 */
export function parseCard(table, today) {
  const index = {};
  for (const [name, pattern] of Object.entries(COLUMN)) {
    const i = table.headers.findIndex((h) => pattern.test(SAFE(h)));
    if (i < 0) throw new Error(`The card table has no "${name}" column any more. The page changed; stopped.`);
    index[name] = i;
  }

  const dated = table.rows
    .map((cells, position) => ({ cells, position, date: dateOf(cells[index.date]) }))
    .filter((r) => r.date !== null && r.date <= today);
  if (dated.length === 0) return null;

  const latest = dated.reduce((a, b) => (b.date > a.date || (b.date === a.date && b.position > a.position) ? b : a));
  const balanceRows = dated.filter((r) => /Сальдо/i.test(SAFE(r.cells[index.content])));
  const saldo = balanceRows.length ? balanceRows.reduce((a, b) => (b.date > a.date || (b.date === a.date && b.position > a.position) ? b : a)) : null;

  return {
    as_of: latest.date,
    debt_minor: money(latest.cells[index.debt], 'debt'),
    overpaid_minor: money(latest.cells[index.overpaid], 'overpayment'),
    penalty_minor: money(latest.cells[index.penalty], 'unpaid penalty'),
    assessed_minor: saldo ? money(saldo.cells[index.accrued], 'accrued') : 0,
    paid_minor: saldo ? money(saldo.cells[index.paid], 'paid') : 0,
  };
}

/** "38 - ЯТМФ ..." -> {code: '38', name}. */
export function splitTaxType(text) {
  const m = /^(\d{1,4})\s*[-–]\s*(.*)$/.exec(SAFE(text));
  return m ? { code: m[1], name: m[2].slice(0, 100) } : null;
}

const optionTexts = (page) => page.locator('.ant-select-dropdown:visible .ant-select-item-option').allInnerTexts().then((a) => a.map(SAFE));

async function openSelect(page, index) {
  await page.locator('.ant-select').nth(index).locator('.ant-select-selector').click({ timeout: 5000 });
  await page.waitForTimeout(700);
}

/**
 * Every tax the card offers, with its current debt, overpayment (prepayment) and unpaid penalty in whole tiyin. The district is the
 * taxpayer's own (the filter offers only that one, or the one named in `district`); the page's own refresh button loads the tax types.
 */
export async function readCard(page, { district = '', today = new Date().toISOString().slice(0, 10) } = {}) {
  await page.goto(`${PORTAL_ORIGIN}${CARD_PATH}`, { waitUntil: 'domcontentloaded', timeout: 45_000 });
  await settle(page);
  await dismissNotice(page);
  if (!isLoggedIn(page.url())) throw new Error('The session ended while reading the tax card.');
  await page.locator('.ant-select').nth(3).waitFor({ state: 'attached', timeout: 20_000 });
  await page.waitForTimeout(2500);

  await openSelect(page, 1);
  const districts = await optionTexts(page);
  const at = district ? districts.findIndex((d) => plain(d).startsWith(plain(district))) : -1;
  const pick = at >= 0 ? at : districts.length === 1 ? 0 : -1;
  if (pick < 0) throw new Error('The tax card offers several districts and none is the company\'s. Set the tax district in the company settings.');
  await page.locator('.ant-select-dropdown:visible .ant-select-item-option').nth(pick).click({ timeout: 4000 });
  await page.waitForTimeout(1500);

  await page.locator('button', { hasText: /янгилаш|yangilash/i }).first().click({ timeout: 5000 }).catch(() => {});
  await page.waitForTimeout(8000);

  await openSelect(page, 3);
  const types = (await optionTexts(page)).map(splitTaxType).filter(Boolean);
  await page.keyboard.press('Escape');
  if (types.length === 0) throw new Error('The tax card offers no tax types. Nothing was read.');

  const balances = [];
  for (const type of types) {
    await openSelect(page, 3);
    await page.locator('.ant-select-dropdown:visible .ant-select-item-option', { hasText: new RegExp(`^${type.code}\\s*[-–]`) }).first().click({ timeout: 4000 });
    await page.waitForTimeout(600);
    await page.locator('button', { hasText: /Кўрсатиш|Ko.rsatish/ }).first().click({ timeout: 5000 });
    await page.locator('.ant-spin-spinning').first().waitFor({ state: 'detached', timeout: 20_000 }).catch(() => {});
    await page.waitForTimeout(3500);

    const table = await page.evaluate(() => {
      const t = document.querySelector('.ant-table');
      return t ? {
        headers: [...t.querySelectorAll('thead th')].map((th) => th.innerText.replace(/\s+/g, ' ').trim()),
        rows: [...t.querySelectorAll('tbody tr:not(.ant-table-placeholder):not(.ant-table-measure-row)')].map((r) => [...r.querySelectorAll('td')].map((c) => c.innerText.replace(/\s+/g, ' ').trim())),
      } : null;
    });
    if (!table) throw new Error(`The tax card table of ${type.code} did not appear.`);

    const standing = parseCard(table, today);
    balances.push({
      tax: CARD_TAX[type.code] ?? 'other',
      name: `${type.code} - ${type.name}`,
      code: type.code,
      as_of: standing?.as_of ?? null,
      debt_minor: standing?.debt_minor ?? 0,
      overpaid_minor: standing?.overpaid_minor ?? 0,
      penalty_minor: standing?.penalty_minor ?? 0,
      assessed_minor: standing?.assessed_minor ?? 0,
      total_minor: (standing?.debt_minor ?? 0) + (standing?.penalty_minor ?? 0),
    });
  }

  return balances;
}
