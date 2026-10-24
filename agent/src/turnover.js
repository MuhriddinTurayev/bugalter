import { createHash } from 'node:crypto';
import { dismissNotice, settle } from './discover.js';
import { parseMinor } from './money.js';
import { readJournal } from './journal.js';
import { NotMapped, need } from './cabinet.js';

// The turnover-tax calculation of an individual entrepreneur in the e-reporting system (oldmy.soliq.uz, form 10190_14),
// observed on 2026-10-24 on the blank form and with the period September chosen (nothing was saved):
//  - header selects: na2_id (tax: 100), type (1 = calculation), year, perioddd (M_1 .. M_12);
//  - after the period is chosen the system fills the whole form itself from e-invoices and online cash registers, so
//    "filling" the form is choosing these four values; no cell is typed;
//  - sheet list-1: the calculation (lines 010 020 030 040 050 060 070 0701 080), sheet list-2: appendix 1 (0101-0105 ...);
//  - the buttons are "Сохранить" (#form-save-button) and "Отправить" (#form-send-button). Only the second is ever pressed,
//    and only after the accountant answered "send" in Bugalter.

const MAIN_CODES = ['010', '020', '030', '040', '050', '060', '070', '0701', '080'];
const APPENDIX_CODES = ['0101', '0102', '0103', '0104', '0105'];

const dateOf = (text) => {
  const m = /(\d{2})\.(\d{2})\.(\d{4})/.exec(String(text));
  return m ? `${m[3]}-${m[2]}-${m[1]}` : null;
};

/** The amount that follows a line code in a row's cells; null when the row has no such code or no amount after it. */
function amountAfter(rows, code) {
  for (const cells of rows) {
    const i = cells.findIndex((c, k) => c === code && k > 0);
    if (i >= 0 && cells[i + 1] !== undefined) {
      const minor = parseMinor(cells[i + 1]);
      if (minor === null) throw new Error(`The amount of line ${code} ("${String(cells[i + 1]).slice(0, 20)}") cannot be read as money. Stopped instead of guessing.`);
      return minor;
    }
  }
  return null;
}

/**
 * The calculation as the cabinet shows it. `sheets` is the text of every row of the form's sheets, each row as its
 * non-empty cells. A required line that is missing stops the read: a missing value is never turned into zero.
 *
 * @param {string[][][]} sheets
 */
export function parseTurnoverForm(sheets) {
  const [main = [], appendix = []] = sheets;

  const lines = {};
  for (const code of MAIN_CODES) lines[code] = amountAfter(main, code);
  for (const code of APPENDIX_CODES) lines[code] = amountAfter(appendix, code);

  const missing = Object.entries(lines).filter(([, v]) => v === null).map(([k]) => k);
  if (missing.length) throw new Error(`The cabinet's form does not show line(s) ${missing.join(', ')} yet. Not read.`);

  const after = (rows, label) => rows.find((cells) => cells[0]?.toLowerCase().startsWith(label))?.[1] ?? null;
  const header = main.find((cells) => cells[0] === 'Вид документа') ?? [];
  const at = (word) => { const i = header.indexOf(word); return i >= 0 ? header[i + 1] : undefined; };
  const month = Number(at('Отчетный период'));
  const year = Number(at('Налоговый период'));
  const tin = (after(main, 'пинфл') ?? '').replace(/\D/g, '');

  if (!Number.isInteger(month) || month < 1 || month > 12 || !/^\d{4}$/.test(String(year))) throw new Error('The form does not show which period it is for. Not read.');

  const pad = (n) => String(n).padStart(2, '0');
  const last = new Date(Date.UTC(year, month, 0)).getUTCDate();
  const rate = lines['060']; // "1.00" (percent) -> 100 basis points
  delete lines['060'];

  return {
    tin,
    type: 'turnover',
    period_start: `${year}-${pad(month)}-01`,
    period_end: `${year}-${pad(month)}-${pad(last)}`,
    due_date: dateOf(main.find((cells) => cells[0]?.toLowerCase().startsWith('срок представления'))?.[1]),
    rate_bp: rate,
    lines,
  };
}

/** What Laravel compares with the approved report before anything is signed. */
export function formFields(parsed) {
  return {
    tin: parsed.tin || null,
    type: parsed.type,
    period_start: parsed.period_start,
    period_end: parsed.period_end,
    base_minor: parsed.lines['050'],
    tax_minor: parsed.lines['070'],
    payable_minor: parsed.lines['080'],
  };
}

/** The draft the portal check sends to Laravel (which validates it again and checks the arithmetic itself). */
export function toDraft(parsed, form) {
  return { type: parsed.type, form, period_start: parsed.period_start, period_end: parsed.period_end, due_date: parsed.due_date, rate_bp: parsed.rate_bp, lines: parsed.lines };
}

function spec(map) {
  const wefo = need(map.forms?.turnover?.wefo, 'forms.turnover.wefo');
  if (!wefo.form_code || !wefo.selects || !wefo.send_button) throw new NotMapped('forms.turnover.wefo (form_code, selects, send_button)');
  return wefo;
}

/** Opens the blank form, chooses tax, kind, year and period (the cabinet then fills the sheets) and reads them. Nothing is saved. */
export async function readTurnover(reporting, map, { year, month }, say = async () => {}) {
  const wefo = spec(map);
  await reporting.goto(`https://oldmy.soliq.uz/wefo4-clientui/form/ru/report/${wefo.form_code}`, { waitUntil: 'domcontentloaded', timeout: 45_000 });
  await settle(reporting);
  await dismissNotice(reporting);
  await reporting.locator(wefo.selects.period).waitFor({ state: 'attached', timeout: 20_000 });

  for (const [key, value] of [['tax', wefo.tax_value], ['kind', wefo.kind_value], ['year', String(year)], ['period', `M_${month}`]]) {
    await reporting.selectOption(wefo.selects[key], value, { timeout: 8_000 });
    await reporting.waitForTimeout(key === 'period' ? 3_500 : 500);
  }
  await dismissNotice(reporting);

  const sheets = await reporting.evaluate(() => [...document.querySelectorAll('table.excel-sheet')].map((t) => [...t.rows]
    .map((r) => [...r.cells].map((c) => c.innerText.replace(/\s+/g, ' ').trim()).filter(Boolean)).filter((cells) => cells.length)));
  const parsed = parseTurnoverForm(sheets);

  const wanted = `${year}-${String(month).padStart(2, '0')}-01`;
  if (parsed.period_start !== wanted) throw new Error(`The form shows the period ${parsed.period_start}, not ${wanted}. Not read.`);

  await say('progress', `Cabinet's own calculation for ${wanted.slice(0, 7)} read`);
  return parsed;
}

/** The month before `now`, as {year, month} (the one a cabinet check looks at when nothing is filed for it). */
export function previousMonth(now = new Date()) {
  const d = new Date(now.getFullYear(), now.getMonth() - 1, 1);
  return { year: d.getFullYear(), month: d.getMonth() + 1 };
}

/**
 * Presses the cabinet's own "Отправить". The accountant confirms the signature in E-IMZO. Then the journal is read again:
 * the new filing is the row for this period whose report number was not there before. Whatever cannot be established
 * from the journal is "unknown", never "not sent"; Laravel then verifies from later cabinet reads.
 */
export async function sendTurnover(reporting, map, { period_start }, { before = [], say = async () => {}, wait = (ms) => reporting.waitForTimeout(ms) } = {}) {
  const wefo = spec(map);
  const formUrl = reporting.url();

  await reporting.locator(wefo.send_button).first().click({ timeout: 15_000 });
  await say('needs_user', 'Confirm the signature in the E-IMZO window (and answer the cabinet if it asks something)', 'eimzo_confirmation');

  let signatureHash = null;
  let toldAboutDialog = false;
  const deadline = Date.now() + 6 * 60_000;

  while (Date.now() < deadline) {
    await wait(2_000);

    if (!signatureHash && !reporting.isClosed()) {
      const pkcs7 = await reporting.locator(wefo.signature_field).first().inputValue({ timeout: 500 }).catch(() => '');
      if (pkcs7) signatureHash = createHash('sha256').update(pkcs7).digest('hex'); // only the hash leaves the computer
    }

    if (reporting.url() !== formUrl) break; // the cabinet moved on from the form

    // A dialog of the cabinet itself (not E-IMZO) that nobody described: a person answers it, the agent keeps watching.
    const dialog = await reporting.evaluate(() => {
      const el = [...document.querySelectorAll('.modal, [role=dialog], .bootbox')].find((m) => { const r = m.getBoundingClientRect(); return r.width > 0 && r.height > 0 && getComputedStyle(m).display !== 'none'; });
      return el ? el.innerText.replace(/\s+/g, ' ').trim().slice(0, 160) : null;
    }).catch(() => null);
    if (dialog && !toldAboutDialog) {
      toldAboutDialog = true;
      await say('needs_user', `The cabinet shows: "${dialog}". Answer it in the browser window`, 'browser');
    }
  }

  // Read the journal: what the cabinet says now is the only proof.
  await reporting.goto(need(map.reporting?.journal_url, 'reporting.journal_url'), { waitUntil: 'domcontentloaded', timeout: 45_000 });
  await settle(reporting);
  const rows = await readJournal(reporting, { formTypes: map.reporting.form_types, entity: 'individual' });
  const fresh = rows.filter((r) => r.type === 'turnover' && r.period_start === period_start && r.report_number && !before.includes(r.report_number));
  fresh.sort((a, b) => Number(b.report_number) - Number(a.report_number));
  const row = fresh[0];

  const base = signatureHash ? { pkcs7_sha256: signatureHash } : {};
  if (!row) return { status: 'unknown', receipt: null, errors: ['The journal shows no new filing for this period after "send".'], ...base };

  return { status: row.status === 'draft' ? 'unknown' : row.status, receipt: row.report_number, errors: row.status === 'rejected' ? [row.state_text] : [], ...base };
}
