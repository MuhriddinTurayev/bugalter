import fs from 'node:fs';
import { openBrowser } from './browser.js';
import { PORTAL_ORIGIN } from './config.js';
import { dismissNotice, settle } from './discover.js';
import { switchToEntrepreneur } from './entrepreneur.js';
import { signIn } from './login.js';

// READ-ONLY: for the two newest payment orders, open the row's "document history" and "view" entries (the "≡" menu of the list) and
// print what the cabinet says: states, who signed, who sent. Nothing is changed; the menu's other entries are never pressed.
const SAFE = (s) => String(s ?? '').replace(/\s+/g, ' ').trim().slice(0, 700);

export async function probeOrders(log = console.log) {
  const { context, page } = await openBrowser();
  try {
    await signIn(page, { tin: '', loginType: 'individual' }, async (kind, message) => log(`${kind}: ${message}`));
    await switchToEntrepreneur(page, async (k, m) => log(`${k}: ${m}`));
    page.on('response', async (r) => {
      const t = r.request().resourceType();
      if ((t !== 'xhr' && t !== 'fetch') || !/payment|history|document|status/i.test(r.url())) return;
      const body = await r.text().catch(() => '');
      if (/states|by-params/.test(r.url())) fs.writeFileSync(`${process.env.APPDATA}/bugalter-agent/discovery/pay-${/states/.test(r.url()) ? 'states' : 'orders'}.json`, body);
      log(`  net ${r.status()} ${new URL(r.url()).pathname.slice(0, 90)} :: ${SAFE(body).slice(0, 600)}`);
    });

    await page.goto(`${PORTAL_ORIGIN}/remotes-services/tax-payment/pay?interactiveId=635`, { waitUntil: 'domcontentloaded', timeout: 45_000 });
    await settle(page);
    await dismissNotice(page);
    await page.waitForTimeout(4000);

    for (const row of [0, 1]) {
      const tr = page.locator('.ant-table tbody tr:not(.ant-table-placeholder):not(.ant-table-measure-row)').nth(row);
      log(`row ${row}: ${SAFE(await tr.innerText()).slice(0, 200)}`);
      await tr.locator('td').first().locator('svg, .anticon, button, span').first().click({ timeout: 4000 }).catch((e) => log(`menu: ${SAFE(e.message).slice(0, 100)}`));
      await page.waitForTimeout(800);
      const items = (await page.locator('.ant-dropdown:visible li, .ant-popover:visible li, [role=menuitem]:visible').allInnerTexts()).map(SAFE);
      log(`  menu: ${JSON.stringify(items)}`);
      const history = page.locator('.ant-dropdown:visible li, [role=menuitem]:visible', { hasText: /Ҳужжат тарихи|Hujjat tarixi|История/i }).first();
      if (await history.count()) {
        await history.click({ timeout: 4000 });
        await page.waitForTimeout(3500);
        const text = SAFE(await page.locator('.ant-modal:visible, .ant-drawer:visible, main').first().innerText().catch(() => ''));
        log(`  history: ${text}`);
        await page.keyboard.press('Escape');
        await page.waitForTimeout(500);
      } else {
        await page.keyboard.press('Escape');
      }
    }
    await page.screenshot({ path: `${process.env.APPDATA}/bugalter-agent/discovery/pay-orders.png` }).catch(() => {});
  } catch (error) {
    log(`problem: ${SAFE(error.message)}`);
  } finally {
    await context.close().catch(() => {});
  }
}
