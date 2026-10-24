import { mkdir, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import { openBrowser } from './browser.js';
import { PORTAL_ORIGIN } from './config.js';
import { DISCOVERY_DIR, dismissNotice, settle } from './discover.js';
import { signIn } from './login.js';

// READ-ONLY probe of the payment pages of the individual-entrepreneur cabinet (my.soliq.uz), seen on the accountant's screen
// on 2026-10-24: the list of payment orders, the "new payment order" form and the personal tax card (KLS). It opens each page,
// records its tables (headers and the first rows), its filters and the options of its dropdowns (a dropdown is opened to read
// its list and closed with Escape; nothing is chosen on the payment form), and on the card page it asks to show one year of
// operations. It never presses a pay/confirm/send/save button and never types into the payment form.
const PAGES = [
  ['orders', '/remotes-services/tax-payment/pay?interactiveId=635'],
  ['new-order', '/remotes-services/tax-payment/pay/add-new'],
  ['card', '/remotes-services/kls/individual-kls'],
];

const SAFE = (s) => String(s ?? '').replace(/\s+/g, ' ').trim().slice(0, 200);

export async function probePayments({ loginType = 'individual' } = {}, log = console.log) {
  await mkdir(DISCOVERY_DIR, { recursive: true });
  const { context, page } = await openBrowser();
  const out = { at: new Date().toISOString(), pages: [], problems: [] };

  try {
    await signIn(page, { tin: '', loginType }, async (kind, message) => log(`${kind}: ${message}`));

    for (const [name, path] of PAGES) {
      try {
        await page.goto(`${PORTAL_ORIGIN}${path}`, { waitUntil: 'domcontentloaded', timeout: 45_000 });
        await settle(page);
        await dismissNotice(page);
        await page.waitForTimeout(2500);

        const info = await page.evaluate(() => {
          const text = (el) => el.innerText.replace(/\s+/g, ' ').trim().slice(0, 160);
          return {
            url: location.pathname + location.search,
            title: document.querySelector('h1')?.innerText.replace(/\s+/g, ' ').trim() ?? null,
            links: [...document.querySelectorAll('main a[href], .PageHead_link, a.PageHead_link')].map((a) => ({ text: text(a), href: a.getAttribute('href') })).slice(0, 12),
            buttons: [...document.querySelectorAll('main button, .ant-btn')].map((b) => ({ text: text(b), type: b.type, cls: b.className.slice(0, 60) })).filter((b) => b.text).slice(0, 30),
            labels: [...document.querySelectorAll('label, .select-label, .ant-form-item-label')].map(text).filter(Boolean).slice(0, 40),
            inputs: [...document.querySelectorAll('input, textarea')].filter((i) => i.type !== 'hidden').map((i) => ({ id: i.id, name: i.name, type: i.type, ph: i.placeholder, ro: i.readOnly, dis: i.disabled })).slice(0, 40),
            tables: [...document.querySelectorAll('.ant-table')].map((t) => ({
              headers: [...t.querySelectorAll('thead th')].map(text),
              rows: [...t.querySelectorAll('tbody tr:not(.ant-table-placeholder):not(.ant-table-measure-row)')].slice(0, 25).map((r) => [...r.querySelectorAll('td')].map(text)),
            })),
          };
        });

        // Dropdown lists: opened to read, closed with Escape; nothing is selected.
        const selects = [];
        const count = await page.locator('.ant-select:not(.ant-select-disabled)').count();
        for (let i = 0; i < Math.min(count, 8); i++) {
          const sel = page.locator('.ant-select:not(.ant-select-disabled)').nth(i);
          try {
            const label = SAFE(await sel.evaluate((el) => (el.closest('.ant-form-item, div')?.parentElement?.innerText ?? '').slice(0, 80)));
            await sel.click({ timeout: 2500 });
            await page.waitForTimeout(600);
            const options = await page.locator('.ant-select-dropdown:visible .ant-select-item-option').allInnerTexts();
            selects.push({ i, label, current: SAFE(await sel.innerText()), options: options.map(SAFE).slice(0, 40) });
            await page.keyboard.press('Escape');
            await page.waitForTimeout(300);
          } catch (error) {
            selects.push({ i, error: SAFE(error.message.split('\n')[0]) });
          }
        }

        // The personal card: ask to show the year's operations (a read-only query).
        let card = null;
        if (name === 'card') {
          try {
            await page.locator('button', { hasText: 'Кўрсатиш' }).first().click({ timeout: 4000 });
            await page.waitForTimeout(5000);
            card = await page.evaluate(() => [...document.querySelectorAll('.ant-table')].map((t) => ({
              headers: [...t.querySelectorAll('thead th')].map((th) => th.innerText.replace(/\s+/g, ' ').trim()),
              rows: [...t.querySelectorAll('tbody tr:not(.ant-table-placeholder):not(.ant-table-measure-row)')].slice(0, 40).map((r) => [...r.querySelectorAll('td')].map((c) => c.innerText.replace(/\s+/g, ' ').trim().slice(0, 120))),
            })));
          } catch (error) {
            out.problems.push(`card: ${SAFE(error.message.split('\n')[0])}`);
          }
        }

        // The first order's action menu (the "≡" on the left): opened to read its entries, closed with Escape.
        let rowMenu = null;
        if (name === 'orders') {
          try {
            await page.locator('.ant-table tbody tr:not(.ant-table-placeholder):not(.ant-table-measure-row) .anticon-menu, .ant-table tbody tr td:first-child svg').first().click({ timeout: 3000 });
            await page.waitForTimeout(700);
            rowMenu = (await page.locator('.ant-dropdown:visible li, .ant-popover:visible li, [role=menuitem]:visible').allInnerTexts()).map(SAFE);
            await page.keyboard.press('Escape');
          } catch (error) {
            rowMenu = `not opened: ${SAFE(error.message.split('\n')[0])}`;
          }
        }

        await page.screenshot({ path: join(DISCOVERY_DIR, `pay-${name}.png`), fullPage: true }).catch(() => {});
        out.pages.push({ name, ...info, selects, card, rowMenu });
        log(`${name}: ${info.tables.length} table(s), ${selects.length} dropdown(s)`);
      } catch (error) {
        out.problems.push(`${name}: ${SAFE(error.message.split('\n')[0])}`);
      }
    }
  } catch (error) {
    out.problems.push(SAFE(error.message));
  } finally {
    await writeFile(join(DISCOVERY_DIR, 'payments-probe.json'), JSON.stringify(out, null, 2));
    await context.close().catch(() => {});
  }

  log(`done: ${out.pages.length} pages, ${out.problems.length} problems -> ${join(DISCOVERY_DIR, 'payments-probe.json')}`);
  return out;
}
