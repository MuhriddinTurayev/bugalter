import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { parseMinor } from './money.js';

const MAP_FILE = fileURLToPath(new URL('../cabinet-map.json', import.meta.url));

export class NotMapped extends Error {
  constructor(what) {
    super(`The cabinet map does not cover "${what}" yet. Record it from a real session (npm run inspect), then have a person verify cabinet-map.json.`);
  }
}

/**
 * The map is read as it is: each section is usable once it has been observed (selectors set). There is no global
 * "verified" switch; a missing piece stops the task at exactly the step that needs it, with its name.
 */
export async function loadMap() {
  return JSON.parse(await readFile(MAP_FILE, 'utf8'));
}

export const need = (value, what) => {
  if (!value) throw new NotMapped(what);
  return value;
};

/**
 * The TIN the cabinet itself shows for the signed-in taxpayer. The agent compares it with the company's TIN and
 * stops on any difference: a wrong company is never read from and never filed for.
 */
export async function readIdentity(page, map) {
  const spec = need(map.identity?.tin?.selector, 'identity.tin');
  const node = page.locator(spec).first();
  await node.waitFor({ timeout: 20_000 });
  const raw = map.identity.tin.attribute ? await node.getAttribute(map.identity.tin.attribute) : await node.innerText();
  const tin = (raw ?? '').replace(/\D/g, '');
  const name = map.identity.name?.selector ? (await page.locator(map.identity.name.selector).first().innerText().catch(() => '')).trim() : null;
  return { tin, name };
}

/**
 * The cabinet must belong to the company. The E-IMZO key the accountant signs in with IS the identity, so nobody types
 * an identifier: the first sign-in of a company that has none yet is trusted ("first use") and Laravel links the company
 * to that cabinet. From then on the cabinet's identifier must equal the company's STIR (9 digits) or PINFL (14 digits);
 * a different one (another key picked by mistake, another taxpayer) stops the task before anything is read or filed.
 *
 * @returns {'known'|'first_use'}
 */
export function assertCompany(identity, company) {
  const valid = (v) => typeof v === 'string' && /^\d{9}$|^\d{14}$/.test(v);
  const known = [company.tin, company.pinfl].filter(valid);

  // A cabinet whose identifier cannot be read is never trusted, not even on first use.
  if (valid(identity.tin) && known.length === 0) return 'first_use';
  if (valid(identity.tin) && known.includes(identity.tin)) return 'known';

  const shown = identity.tin ? `${identity.tin.length} digits ending ${identity.tin.slice(-3)}` : 'unknown';
  const error = new Error(`Wrong taxpayer: the cabinet is open for an identifier (${shown}) that is not the one this company is linked to. Nothing was read or filed.`);
  if (valid(identity.tin)) error.seenIdentifier = identity.tin; // lets the owner re-link with one click if the key really changed
  throw error;
}

/** Reads the filed reports and balances as the site shows them. Everything is text from outside: sent as data, validated by Laravel. */
export async function readSnapshot(page, map, identity) {
  const reports = [];
  const list = map.reports_list;

  await page.goto(need(list?.url, 'reports_list.url'), { waitUntil: 'domcontentloaded', timeout: 45_000 });
  const rows = page.locator(need(list.row, 'reports_list.row'));
  await rows.first().waitFor({ timeout: 20_000 }).catch(() => {});

  for (const row of await rows.all()) {
    const cell = async (key) => (list.columns?.[key] ? ((await row.locator(list.columns[key]).first().innerText().catch(() => '')) ?? '').trim() : '');
    const status = statusOf(await cell('status'), list.status_words ?? {});
    const form = await cell('form');
    const [start, end] = datesIn(await cell('period'));
    reports.push({
      type: typeOf(form, list.form_types ?? {}),
      form,
      name: form,
      period_start: start ?? null,
      period_end: end ?? start ?? null,
      status,
      submitted_at: datesIn(await cell('submitted_at'))[0] ?? null,
      receipt: (await cell('receipt')) || null,
    });
  }

  return { taxpayer: { tin: identity.tin, name: identity.name }, reports, balances: [], messages: [] };
}

/** dd.mm.yyyy dates in a text, as Y-m-d. */
export function datesIn(text) {
  return [...String(text).matchAll(/\b(\d{2})\.(\d{2})\.(\d{4})\b/g)].map(([, d, m, y]) => `${y}-${m}-${d}`);
}

/** The Bugalter report type for a form name, from the map's substring table; null when unknown (never guessed). */
export function typeOf(formName, table) {
  const t = formName.toLowerCase();
  for (const [needle, type] of Object.entries(table)) if (t.includes(needle.toLowerCase())) return type;
  return null;
}

export function statusOf(text, words) {
  const t = text.toLowerCase();
  for (const [status, list] of Object.entries(words)) if (list.some((w) => t.includes(String(w).toLowerCase()))) return status;
  return 'submitted'; // unknown wording is shown as "received", never as accepted
}

/**
 * Types the approved values into the form of the report and reads back what the form now says. Amounts are typed
 * as so'm text derived from integer tiyin; reading back goes through parseMinor, never a float.
 */
export async function fillForm(page, map, payload) {
  const type = payload.report.report.type;
  const form = map.forms?.[type];
  const fields = need(form?.fields && Object.keys(form.fields).length ? form.fields : null, `forms.${type}.fields`);
  await page.goto(need(form.url, `forms.${type}.url`), { waitUntil: 'domcontentloaded', timeout: 45_000 });

  const r = payload.report.report;
  const values = { tin: payload.report.taxpayer.tin, period_start: r.period_start, period_end: r.period_end, base: soum(r.base_minor), tax: soum(r.tax_minor), payable: soum(r.payable_minor) };
  for (const [key, selector] of Object.entries(fields)) {
    if (selector && key in values) await page.locator(selector).first().fill(String(values[key]));
  }

  const read = async (key) => (fields[key] ? await page.locator(fields[key]).first().inputValue().catch(async () => page.locator(fields[key]).first().innerText()) : null);
  return {
    tin: (await read('tin'))?.replace(/\D/g, '') ?? null,
    type,
    period_start: await read('period_start'),
    period_end: await read('period_end'),
    base_minor: parseMinor((await read('base')) ?? ''),
    tax_minor: parseMinor((await read('tax')) ?? ''),
    payable_minor: parseMinor((await read('payable')) ?? ''),
  };
}

/** Integer tiyin -> so'm text with two decimals, by integer arithmetic. */
export function soum(minor) {
  const abs = Math.abs(minor);
  return `${minor < 0 ? '-' : ''}${Math.trunc(abs / 100)},${String(abs % 100).padStart(2, '0')}`;
}
