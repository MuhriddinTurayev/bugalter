import { PORTAL_ORIGIN } from './config.js';
import { dismissNotice, settle } from './discover.js';
import { isLoggedIn } from './login.js';
import { formatMinor } from './money.js';
import { parsePayments, readPayments } from './payments.js';

// Making one tax payment in the cabinet of an individual entrepreneur (my.soliq.uz). Seen on 2026-10-24 (read only, nothing was
// paid by the agent): /remotes-services/tax-payment/pay/add-new has five dropdowns in this order: region, district, tax type, payment
// type, (the taxpayer's PINFL, disabled), payer account; text fields: payment order number, amount, payment details; read-only
// fields the cabinet fills itself (payment_date, payers_name, payers_bank_*, cuirsive_sum = the amount in words, beneficiary_*,
// treasury_personal_account_one); buttons "Янгилаш" (refresh a dropdown's list) and «Давом этиш» (continue), which opens a window
// "check the data you entered" with a cancel button and a primary button. The agent stops and asks the accountant at anything it was
// not told about; it never answers a bank or one-time-code window itself.
export const PAY_PATH = '/remotes-services/tax-payment/pay/add-new';

const SELECT = { region: 0, district: 1, tax: 2, kind: 3, account: 5 };
const CONTINUE = /Давом этиш|Davom etish|Продолжить|Continue/i;
const REFRESH = /Янгилаш|Yangilash|Обновить/i;
const CANCEL = /Отменить|Бекор қилиш|Bekor qilish|Cancel/i;

const TRANSLIT = { а: 'a', б: 'b', в: 'v', г: 'g', ғ: 'g', д: 'd', е: 'e', ё: 'yo', ж: 'j', з: 'z', и: 'i', й: 'y', к: 'k', қ: 'q', л: 'l', м: 'm', н: 'n', о: 'o', ў: 'o', п: 'p', р: 'r', с: 's', т: 't', у: 'u', ф: 'f', х: 'x', ҳ: 'h', ц: 'ts', ч: 'ch', ш: 'sh', щ: 'sh', ъ: '', ь: '', ы: 'i', э: 'e', ю: 'yu', я: 'ya' };

/** A name as letters only, Cyrillic or Latin, so that "Учтепа" and "Uchtepa" (or "Тошкент шаҳар" and "Toshkent shahar") compare equal. */
export function plain(text) {
  return [...String(text ?? '').toLowerCase()].map((c) => TRANSLIT[c] ?? c).join('').replace(/[^a-z0-9]/g, '').replace(/['’ʻʼ`]/g, '');
}

/** The first option whose text is, or starts with, the wanted one (by `plain`); null when none does. */
export function pickOption(options, wanted) {
  const w = plain(wanted);
  return w === '' ? null : options.findIndex((o) => plain(o).startsWith(w)) >= 0 ? options.findIndex((o) => plain(o).startsWith(w)) : null;
}

/** The option that begins with a code ("100 - Айланмадан ...", "08101-Солиқлар ..."), by the digits before the first dash/space. */
export function pickCode(options, code) {
  const i = options.findIndex((o) => String(o).trim().replace(/^(\d+)[\s\-–].*$/, '$1') === code);
  return i >= 0 ? i : null;
}

/** The payer account to choose: the one that contains the company's bank account; with none given, the only one offered. */
export function pickAccount(options, wanted) {
  const digits = String(wanted ?? '').replace(/\D/g, '');
  if (digits) {
    const i = options.findIndex((t) => t.replace(/\D/g, '').includes(digits));
    return i >= 0 ? i : null;
  }
  return options.length === 1 ? 0 : null;
}

/** "1 000 000" so'm (and tiyin) for the cabinet's amount field: digits, a point and two digits only when there are tiyin. */
export function amountText(minor) {
  const whole = String(Math.trunc(minor / 100));
  const frac = String(minor % 100).padStart(2, '0');
  return minor % 100 === 0 ? whole : `${whole}.${frac}`;
}

/** The payment order number to use: one more than the biggest the cabinet already lists for this year. */
export function nextOrderNumber(payments, year) {
  const numbers = payments.filter((p) => String(p.date).startsWith(String(year))).map((p) => Number(p.number)).filter((n) => Number.isInteger(n) && n > 0);
  return String((numbers.length ? Math.max(...numbers) : 0) + 1);
}

/** The digits of a window's text with spaces removed: used to look for the amount in it. */
const digitsOf = (text) => String(text).replace(/\s+/g, '');

/** Does the confirmation window show the amount, the tax code and the payment type that were approved? */
export function modalMatches(text, { amountMinor, taxCode, kind }) {
  const flat = digitsOf(text);
  const whole = String(Math.trunc(amountMinor / 100));
  return flat.includes(whole) && String(text).includes(taxCode) && String(text).includes(kind);
}

async function openSelect(page, index) {
  const select = page.locator('.ant-select').nth(index);
  await select.locator('.ant-select-selector').click({ timeout: 5000 });
  await page.waitForTimeout(600);
  return select;
}

const optionTexts = (page) => page.locator('.ant-select-dropdown:visible .ant-select-item-option').allInnerTexts().then((a) => a.map((t) => t.replace(/\s+/g, ' ').trim()));

/** The text a dropdown shows as chosen (empty when nothing is chosen). */
async function chosen(page, index) {
  return (await page.locator('.ant-select').nth(index).locator('.ant-select-selection-item').first().innerText().catch(() => '')).replace(/\s+/g, ' ').trim();
}

/**
 * Chooses an option of a dropdown. If its list is empty the page's own "refresh" button is pressed (the first one for the tax
 * type, the second for the payer account) and the list awaited. If the option still cannot be chosen, the accountant is asked to
 * choose it in the open browser window and the agent waits for the choice, so the payment always moves forward by a person's hand
 * and never by a guess.
 */
async function choose(page, { index, find, label, refresh = null, say, waitMs = 4 * 60_000 }) {
  let options = [];
  try {
    await openSelect(page, index);
    options = await optionTexts(page);
    if (options.length === 0 && refresh !== null) {
      await page.keyboard.press('Escape');
      await page.locator('button', { hasText: REFRESH }).nth(refresh).click({ timeout: 4000 }).catch(() => {});
      for (let i = 0; i < 6 && options.length === 0; i++) {
        await page.waitForTimeout(2500);
        await openSelect(page, index);
        options = await optionTexts(page);
        if (options.length === 0) await page.keyboard.press('Escape');
      }
    }

    const at = find(options);
    if (at !== null) {
      await page.locator('.ant-select-dropdown:visible .ant-select-item-option').nth(at).click({ timeout: 4000 });
      await page.waitForTimeout(900);
      return;
    }
    await page.keyboard.press('Escape');
  } catch {
    await page.keyboard.press('Escape').catch(() => {});
  }

  await say('needs_user', `Choose "${label}" in the browser window (the list did not offer it by itself)`, 'browser');
  const until = Date.now() + waitMs;
  while (Date.now() < until) {
    if ((await chosen(page, index)) !== '') return;
    await page.waitForTimeout(2000);
  }
  throw new Error(`"${label}" was not chosen in time. Nothing was paid.`);
}

async function readForm(page) {
  return page.evaluate(() => {
    const v = (name) => document.querySelector(`input[name="${name}"]`)?.value ?? '';
    const typed = [...document.querySelectorAll('input[type=text]')].filter((i) => !i.disabled && !i.readOnly && i.placeholder && !i.name);
    return {
      order_number: typed[0]?.value ?? '',
      amount_text: typed[1]?.value ?? '',
      words: v('cuirsive_sum'),
      beneficiary_account: v('beneficiary_debit_account'),
      treasury_account: v('treasury_personal_account_one'),
      payer_bank_code: v('payers_bank_code'),
      payment_details: v('payment_details'),
    };
  });
}

/** An amount field's text -> tiyin ("1 000 000", "1000000.50", "1 000,5"); null when it is not an amount. */
export function amountFromField(text) {
  const t = String(text).replace(/\s+/g, '');
  const m = /^(\d{1,15})(?:[.,](\d{1,2}))?$/.exec(t);
  return m ? Number(m[1]) * 100 + Number((m[2] ?? '').padEnd(2, '0') || '0') : null;
}

/**
 * Fills the payment form, shows the cabinet's own confirmation window to Laravel, and — only if Laravel says it matches what the
 * person approved — presses the window's confirm button. The money moves in the cabinet; this reads what the cabinet shows.
 */
export async function payTax(page, task, { api, say, wait = (ms) => page.waitForTimeout(ms) }) {
  const pay = task.payload.payment;

  // What the cabinet already lists, to recognise the new order afterwards and to choose its number.
  const before = await readPayments(page, { years: 1 });
  const known = new Set(before.map((p) => `${p.number}|${p.date}|${p.tax_code}`));
  const number = nextOrderNumber(before, new Date().getFullYear());

  await page.goto(`${PORTAL_ORIGIN}${PAY_PATH}`, { waitUntil: 'domcontentloaded', timeout: 45_000 });
  await settle(page);
  await dismissNotice(page);
  if (!isLoggedIn(page.url())) throw new Error('The session ended before the payment form opened.');
  await page.locator('.ant-select').nth(SELECT.tax).waitFor({ state: 'attached', timeout: 20_000 });
  await page.waitForTimeout(2000);

  await say('progress', 'Choosing the region and district');
  if (pay.region) await choose(page, { index: SELECT.region, find: (o) => pickOption(o, pay.region), label: 'Region', say });
  if (pay.district) await choose(page, { index: SELECT.district, find: (o) => pickOption(o, pay.district), label: 'District', say });

  await say('progress', `Choosing the tax ${pay.tax_code} and the payment type ${pay.kind}`);
  await choose(page, { index: SELECT.tax, find: (o) => pickCode(o, pay.tax_code), label: `Tax ${pay.tax_code}`, refresh: 0, say });
  await choose(page, { index: SELECT.kind, find: (o) => pickCode(o, pay.kind), label: `Payment type ${pay.kind}`, say });
  await choose(page, {
    index: SELECT.account,
    find: (o) => pickAccount(o, pay.payer_account),
    label: 'Payer account',
    refresh: 1,
    say,
  });

  await say('progress', 'Typing the order number and the amount');
  // The two fields a person types into have a placeholder ("Киритинг") and no name: the order number first, then the amount.
  const boxes = await page.locator('input[type=text]:not([disabled]):not([readonly])').all();
  const fillable = [];
  for (const box of boxes) if ((await box.getAttribute('placeholder')) && !(await box.getAttribute('name'))) fillable.push(box);
  if (fillable.length < 2) throw new Error('The payment form has no order number and amount fields where they were expected. The page changed; nothing was paid.');
  await fillable[0].fill(number);
  await fillable[1].fill(amountText(pay.amount_minor));
  if (pay.purpose) await page.locator('input[name="payment_details"]').fill(pay.purpose);
  await page.waitForTimeout(1200);

  const form = await readForm(page);
  const taxShown = (await chosen(page, SELECT.tax)).replace(/^(\d+)[\s\-–].*$/, '$1');
  const kindShown = (await chosen(page, SELECT.kind)).replace(/^(\d+)[\s\-–].*$/, '$1');
  const amountShown = amountFromField(form.amount_text);
  if (taxShown !== pay.tax_code || kindShown !== pay.kind || amountShown !== pay.amount_minor) {
    throw new Error(`The form shows tax ${taxShown || '?'}, type ${kindShown || '?'}, amount ${amountShown ?? '?'} instead of the approved ones. Nothing was paid.`);
  }
  if (!form.words || !form.beneficiary_account || !form.treasury_account) throw new Error('The cabinet did not fill in the amount in words or the receiver accounts. Nothing was paid.');

  await say('progress', 'Asking the cabinet to check the data');
  await page.locator('button', { hasText: CONTINUE }).first().click({ timeout: 8000 });
  const modal = page.locator('.ant-modal-wrap:visible, .ant-modal:visible').first();
  await modal.waitFor({ state: 'visible', timeout: 20_000 });
  const modalText = (await modal.innerText()).replace(/\s+/g, ' ').trim();

  if (!modalMatches(modalText, { amountMinor: pay.amount_minor, taxCode: pay.tax_code, kind: pay.kind })) {
    await modal.locator('button', { hasText: CANCEL }).first().click({ timeout: 3000 }).catch(() => page.keyboard.press('Escape'));
    throw new Error('The cabinet\'s confirmation window does not show the approved amount, tax and payment type. Nothing was paid.');
  }

  const check = await api.paymentCheck(task.id, { tax_code: taxShown, kind: kindShown, amount_minor: amountShown });
  if (!check.proceed) {
    await modal.locator('button', { hasText: CANCEL }).first().click({ timeout: 3000 }).catch(() => page.keyboard.press('Escape'));
    if (check.dry_run) return { checked: true, status: 'checked', errors: [] }; // the server closed the task: nothing is paid in a check
    throw new Error(`Not paid: the form does not match the approved payment (${check.mismatches.join(', ')}).`);
  }

  // From here a failure is "unknown": the money may have moved.
  await say('progress', `Confirming the payment of ${formatMinor(pay.amount_minor)} so'm in the cabinet`);
  await modal.locator('button.ant-btn-primary').last().click({ timeout: 8000 });

  let toldAbout = '';
  const urlBefore = page.url();
  const until = Date.now() + 4 * 60_000;
  while (Date.now() < until) {
    await wait(2500);

    // Any further window (a bank, a one-time code, a signature) is for a person, never answered here.
    const dialog = await page.evaluate(() => {
      const el = [...document.querySelectorAll('.ant-modal-wrap .ant-modal')].find((m) => { const r = m.getBoundingClientRect(); return r.width > 0 && r.height > 0 && getComputedStyle(m.closest('.ant-modal-wrap')).display !== 'none'; });
      const text = el ? el.innerText.replace(/\s+/g, ' ').trim() : '';
      return text ? text.slice(0, 160) : null;
    }).catch(() => null);
    if (dialog && dialog !== toldAbout && dialog !== modalText.slice(0, 160)) {
      toldAbout = dialog;
      await say('needs_user', `The cabinet asks: "${dialog}". Answer it in the browser window`, 'browser');
    }

    if (page.url() !== urlBefore && !page.url().includes('/add-new')) break;
    if (!dialog) {
      const toast = await page.locator('.Toastify__toast, .ant-message-notice').allInnerTexts().catch(() => []);
      if (toast.length) break;
    }
  }

  // What the cabinet's list says now is the only proof.
  let found = null;
  for (let attempt = 0; attempt < 6 && !found; attempt++) {
    if (attempt > 0) await wait(10_000);
    await page.goto(`${PORTAL_ORIGIN}/remotes-services/tax-payment/pay?interactiveId=635`, { waitUntil: 'domcontentloaded', timeout: 45_000 });
    await settle(page);
    const rows = await parsePayments(page).catch(() => []);
    found = rows.find((p) => p.tax_code === pay.tax_code && p.amount_minor === pay.amount_minor && !known.has(`${p.number}|${p.date}|${p.tax_code}`)) ?? null;
  }

  if (!found) return { status: 'unknown', order_number: null, errors: ['The cabinet\'s list shows no new payment order for this amount after the confirmation.'] };

  return { status: found.paid ? 'paid' : 'submitted', order_number: found.number, errors: [] };
}
