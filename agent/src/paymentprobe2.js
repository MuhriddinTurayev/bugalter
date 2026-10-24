import { mkdir, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import { openBrowser } from './browser.js';
import { PORTAL_ORIGIN } from './config.js';
import { DISCOVERY_DIR, dismissNotice, settle } from './discover.js';
import { signIn } from './login.js';

// READ-ONLY, second look at the payment pages. (1) The personal tax card (KLS): for every tax type its dropdown offers, the
// type is chosen and "Кўрсатиш" (show) pressed; the operations table is read. (2) The new payment order form: its dropdowns
// are opened to read their lists, the tax type "100" is chosen (client-side state) to see which fields fill themselves in;
// the page is then left WITHOUT pressing «Давом этиш» and nothing is typed into any field.
const SAFE = (s) => String(s ?? '').replace(/\s+/g, ' ').trim().slice(0, 240);

async function openSelect(page, index) {
  const sel = page.locator('.ant-select').nth(index);
  await sel.locator('.ant-select-selector').click({ timeout: 3000 });
  await page.waitForTimeout(700);
  return sel;
}

async function chooseDistrict(page, name, problems) {
  try {
    await openSelect(page, 1);
    await page.locator('.ant-select-dropdown:visible .ant-select-item-option', { hasText: name }).first().click({ timeout: 3000 });
    await page.waitForTimeout(800);
  } catch (error) {
    problems.push('district: ' + SAFE(error.message));
  }
}

/** After a refresh the page puts the taxpayer's own district into the filter; wait (up to 40 s) for it to stop being the default. */
async function waitForDistrict(page, out) {
  for (let i = 0; i < 40; i++) {
    const text = SAFE(await page.locator('.ant-select').nth(1).innerText().catch(() => ''));
    if (text && !/Чилонзор/.test(text)) { (out.districts ??= []).push(text); return; }
    await page.waitForTimeout(1000);
  }
  (out.districts ??= []).push('(district stayed default)');
}

const optionTexts = (page) => page.locator('.ant-select-dropdown:visible .ant-select-item-option').allInnerTexts().then((a) => a.map(SAFE));

const tableRows = (page) => page.evaluate(() => [...document.querySelectorAll('.ant-table')].map((t) => ({
  headers: [...t.querySelectorAll('thead th')].map((th) => th.innerText.replace(/\s+/g, ' ').trim()),
  rows: [...t.querySelectorAll('tbody tr:not(.ant-table-placeholder):not(.ant-table-measure-row)')].slice(0, 80).map((r) => [...r.querySelectorAll('td')].map((c) => c.innerText.replace(/\s+/g, ' ').trim().slice(0, 140))),
})));

export async function probePayments2({ loginType = 'individual' } = {}, log = console.log) {
  await mkdir(DISCOVERY_DIR, { recursive: true });
  const { context, page } = await openBrowser();
  const out = { at: new Date().toISOString(), card: {}, newOrder: {}, problems: [] };

  try {
    await signIn(page, { tin: '', loginType }, async (kind, message) => log(`${kind}: ${message}`));

    // (1) the personal card
    await page.goto(`${PORTAL_ORIGIN}/remotes-services/kls/individual-kls`, { waitUntil: 'domcontentloaded', timeout: 45_000 });
    await settle(page);
    await dismissNotice(page);
    await page.waitForTimeout(2500);
    // The lists are filled from the cabinet by the page's own refresh button (a query, nothing is changed).
    await page.locator('button', { hasText: 'Ҳудудлардаги малумотларни янгилаш' }).first().click({ timeout: 4000 }).catch((e) => out.problems.push(`refresh card: ${SAFE(e.message)}`));
    await waitForDistrict(page, out);
    await openSelect(page, 4);
    const taxTypes = await optionTexts(page);
    await page.keyboard.press('Escape');
    out.card.tax_types = taxTypes;
    log(`card tax types: ${taxTypes.join(' ; ')}`);

    out.card.by_tax = {};
    for (const type of taxTypes) {
      try {
        await openSelect(page, 4);
        await page.locator('.ant-select-dropdown:visible .ant-select-item-option', { hasText: type }).first().click({ timeout: 3000 });
        await page.waitForTimeout(500);
        await page.locator('button', { hasText: 'Кўрсатиш' }).first().click({ timeout: 4000 });
        await page.waitForTimeout(4500);
        out.card.by_tax[type] = await tableRows(page);
        log(`  ${type.slice(0, 30)}: ${out.card.by_tax[type][0]?.rows.length ?? 0} row(s)`);
      } catch (error) {
        out.problems.push(`card ${type.slice(0, 20)}: ${SAFE(error.message.split('\n')[0])}`);
      }
    }
    await page.screenshot({ path: join(DISCOVERY_DIR, 'pay-card-tax.png'), fullPage: true }).catch(() => {});

    // (2) the new payment order form, never submitted
    await page.goto(`${PORTAL_ORIGIN}/remotes-services/tax-payment/pay/add-new`, { waitUntil: 'domcontentloaded', timeout: 45_000 });
    await settle(page);
    await dismissNotice(page);
    await page.waitForTimeout(2500);

    await page.locator('button', { hasText: 'Янгилаш' }).first().click({ timeout: 4000 }).catch((e) => out.problems.push(`refresh order: ${SAFE(e.message)}`));
    await waitForDistrict(page, out);
    await openSelect(page, 2);
    out.newOrder.tax_types = await optionTexts(page);
    log(`new order tax types: ${out.newOrder.tax_types.join(' ; ')}`);
    await page.locator('.ant-select-dropdown:visible .ant-select-item-option', { hasText: /^100/ }).first().click({ timeout: 3000 }).catch((e) => out.problems.push(`pick 100: ${SAFE(e.message)}`));
    await page.waitForTimeout(2500);

    await openSelect(page, 3);
    out.newOrder.payment_types = await optionTexts(page);
    await page.keyboard.press('Escape');

    await openSelect(page, 4).catch(() => null);
    out.newOrder.payer_accounts = await optionTexts(page).catch(() => []);
    await page.keyboard.press('Escape');

    out.newOrder.fields = await page.evaluate(() => [...document.querySelectorAll('input, textarea')].filter((i) => i.type !== 'hidden' && i.type !== 'search').map((i) => ({
      name: i.name, ph: i.placeholder, disabled: i.disabled, value: i.name.includes('account') || i.name.includes('tin') || i.name.includes('bank') || i.name.includes('beneficiary') || i.name.includes('treasury') || i.name === 'payment_details' || i.name === 'cuirsive_sum' ? String(i.value).slice(0, 80) : '(not read)',
    })));
    await page.screenshot({ path: join(DISCOVERY_DIR, 'pay-new-order-100.png'), fullPage: true }).catch(() => {});
    // the page is left as it is; nothing was typed and «Давом этиш» was never pressed
    await page.goto(`${PORTAL_ORIGIN}/remotes-services/tax-payment/pay`, { waitUntil: 'domcontentloaded' }).catch(() => {});
  } catch (error) {
    out.problems.push(SAFE(error.message));
  } finally {
    await writeFile(join(DISCOVERY_DIR, 'payments-probe2.json'), JSON.stringify(out, null, 2));
    await context.close().catch(() => {});
  }

  log(`done: ${out.problems.length} problems -> ${join(DISCOVERY_DIR, 'payments-probe2.json')}`);
  return out;
}
