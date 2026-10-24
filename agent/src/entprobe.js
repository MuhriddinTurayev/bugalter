import { mkdir, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import { openBrowser } from './browser.js';
import { PORTAL_ORIGIN } from './config.js';
import { DISCOVERY_DIR, dismissNotice, settle } from './discover.js';
import { signIn } from './login.js';

// READ-ONLY look at the entrepreneur's own cabinet ("ЯТТнинг шахсий кабинети"): switch to it with the header's switch button,
// list the service cards that lead to the e-reporting system, read the personal tax card (KLS) for every tax type it offers
// (a dropdown is opened and one type chosen, then "Кўрсатиш" pressed: a query), and the two lists of letters.
const SAFE = (s) => String(s ?? '').replace(/\s+/g, ' ').trim().slice(0, 220);
const out = { cards: [], card: {}, messages: {}, problems: [] };

const rows = (page) => page.evaluate(() => [...document.querySelectorAll('.ant-table')].map((t) => ({
  headers: [...t.querySelectorAll('thead th')].map((th) => th.innerText.replace(/\s+/g, ' ').trim()),
  rows: [...t.querySelectorAll('tbody tr:not(.ant-table-placeholder):not(.ant-table-measure-row)')].slice(0, 80).map((r) => [...r.querySelectorAll('td')].map((c) => c.innerText.replace(/\s+/g, ' ').trim().slice(0, 140))),
})));

export async function probeEntrepreneur(log = console.log) {
  await mkdir(DISCOVERY_DIR, { recursive: true });
  const { context, page } = await openBrowser();

  try {
    await signIn(page, { tin: '', loginType: 'individual' }, async (kind, message) => log(`${kind}: ${message}`));
    await page.locator('button', { hasText: /^(Ўтиш|O.tish)$/ }).first().click({ timeout: 5000 });
    await page.waitForTimeout(700);
    await page.locator('.ant-dropdown:visible li, [role=menuitem]:visible, .ant-dropdown:visible a', { hasText: /Якка тартибдаги тадбиркор|Yakka tartibdagi tadbirkor/i }).first().click({ timeout: 5000 });
    await page.waitForTimeout(7000);
    await settle(page);
    await dismissNotice(page);
    log(`cabinet: ${SAFE(await page.locator('header, .Header_host_header').first().innerText().catch(() => '')).slice(0, 80)}`);

    // service cards that lead to the e-reporting system
    await page.goto(`${PORTAL_ORIGIN}/with-sidebar/services`, { waitUntil: 'domcontentloaded', timeout: 45_000 });
    await settle(page);
    await dismissNotice(page);
    await page.waitForTimeout(3000);
    out.cards = await page.evaluate(() => [...document.querySelectorAll('a[href]')].filter((a) => /wefo|redirect-to|tax-payment|kls|declaration/i.test(a.getAttribute('href'))).map((a) => ({ text: a.innerText.replace(/\s+/g, ' ').trim().slice(0, 100), href: a.getAttribute('href').slice(0, 120) })));
    log(`service links: ${out.cards.length}`);
    for (const c of out.cards.slice(0, 25)) log(`  • ${c.text} → ${c.href}`);

    // the personal tax card, per tax type
    await page.goto(`${PORTAL_ORIGIN}/remotes-services/kls/individual-kls`, { waitUntil: 'domcontentloaded', timeout: 45_000 });
    await settle(page);
    await dismissNotice(page);
    await page.waitForTimeout(4000);
    log(`card page: ${SAFE(await page.locator('h1').first().innerText().catch(() => ''))} | ${JSON.stringify((await page.locator('.ant-select').allInnerTexts()).map(SAFE))}`);

    // the district is empty until chosen: take the taxpayer's own (the form's list offers it), then the tax types follow
    await page.locator('.ant-select').nth(1).locator('.ant-select-selector').click({ timeout: 4000 });
    await page.waitForTimeout(900);
    const districts = (await page.locator('.ant-select-dropdown:visible .ant-select-item-option').allInnerTexts()).map(SAFE);
    log(`districts: ${JSON.stringify(districts.slice(0, 12))}`);
    await page.locator('.ant-select-dropdown:visible .ant-select-item-option', { hasText: /Учтепа|Uchtepa/i }).first().click({ timeout: 4000 }).catch((e) => out.problems.push('district: ' + SAFE(e.message)));
    await page.waitForTimeout(1500);
    await page.locator('button', { hasText: /янгилаш|yangilash/i }).first().click({ timeout: 4000 }).catch(() => {});
    await page.waitForTimeout(8000);
    log(`card filters: ${JSON.stringify((await page.locator('.ant-select').allInnerTexts()).map(SAFE))}`);

    const taxSelect = page.locator('.ant-select').nth(3);
    await taxSelect.locator('.ant-select-selector').click({ timeout: 4000 });
    await page.waitForTimeout(900);
    const types = (await page.locator('.ant-select-dropdown:visible .ant-select-item-option').allInnerTexts()).map(SAFE);
    await page.keyboard.press('Escape');
    log(`card tax types: ${JSON.stringify(types)}`);

    for (const type of types) {
      try {
        await taxSelect.locator('.ant-select-selector').click({ timeout: 4000 });
        await page.waitForTimeout(500);
        await page.locator('.ant-select-dropdown:visible .ant-select-item-option', { hasText: type.slice(0, 12) }).first().click({ timeout: 4000 });
        await page.waitForTimeout(600);
        await page.locator('button', { hasText: /Кўрсатиш|Ko.rsatish/ }).first().click({ timeout: 4000 });
        await page.waitForTimeout(5000);
        out.card[type] = await rows(page);
        log(`  ${type.slice(0, 40)}: ${out.card[type][0]?.rows.length ?? 0} row(s)`);
      } catch (error) {
        out.problems.push(`card ${type.slice(0, 20)}: ${SAFE(error.message.split('\n')[0])}`);
      }
    }

    await page.screenshot({ path: join(DISCOVERY_DIR, 'ent-card.png'), fullPage: true }).catch(() => {});

    // the letters
    for (const kind of ['requirement', 'notice']) {
      await page.goto(`${PORTAL_ORIGIN}/interactive/shared-services/all-messages/claim/${kind}`, { waitUntil: 'domcontentloaded', timeout: 45_000 });
      await settle(page);
      await dismissNotice(page);
      await page.waitForTimeout(4000);
      out.messages[kind] = await rows(page);
      log(`${kind}: ${out.messages[kind][0]?.rows.length ?? 0} row(s)`);
    }
  } catch (error) {
    out.problems.push(SAFE(error.message));
    log(`problem: ${SAFE(error.message)}`);
  } finally {
    await writeFile(join(DISCOVERY_DIR, 'entrepreneur-probe.json'), JSON.stringify(out, null, 2));
    await context.close().catch(() => {});
  }
}
