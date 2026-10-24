import { openBrowser } from './browser.js';
import { PORTAL_ORIGIN } from './config.js';
import { dismissNotice, settle } from './discover.js';
import { signIn } from './login.js';

// READ-ONLY: the accountant works in the entrepreneur's own cabinet ("YaTTning shaxsiy kabineti"), reached from the
// individual cabinet by the header's switch button ("Ўтиш"). This probe switches to it (a navigation, nothing is changed),
// then opens the payment form and reads how the tax types are offered there. Nothing is typed or paid.
const SAFE = (s) => String(s ?? '').replace(/\s+/g, ' ').trim().slice(0, 200);

export async function probePayments3(log = console.log) {
  const { context, page } = await openBrowser();
  try {
    await signIn(page, { tin: '', loginType: 'individual' }, async (kind, message) => log(`${kind}: ${message}`));
    page.on('response', async (r) => {
      const t = r.request().resourceType();
      if (t !== 'xhr' && t !== 'fetch') return;
      const body = await r.text().catch(() => '');
      log(`  net ${r.status()} ${r.request().method()} ${new URL(r.url()).pathname.slice(0, 100)} :: ${SAFE(body).slice(0, 130)}`);
    });

    const title = async () => SAFE(await page.locator('h1').first().innerText().catch(() => ''));
    const sub = async () => SAFE(await page.locator('header, .Header_host_header').first().innerText().catch(() => '')).slice(0, 120);

    log(`start: ${await title()} | ${await sub()}`);
    const switcher = page.locator('button', { hasText: /^(Ўтиш|O.tish|Переход|Перейти)$/ }).first();
    log(`switch button: ${await switcher.count()}`);
    await switcher.click({ timeout: 5000 });
    await page.waitForTimeout(800);
    const items = (await page.locator('.ant-dropdown:visible li, [role=menuitem]:visible, .ant-dropdown:visible a').allInnerTexts()).map(SAFE);
    log(`switch menu: ${JSON.stringify(items)}`);

    const target = page.locator('.ant-dropdown:visible li, [role=menuitem]:visible, .ant-dropdown:visible a', { hasText: /Якка тартибдаги тадбиркор|Yakka tartibdagi tadbirkor|индивидуальн/i }).first();
    if (await target.count()) {
      await target.click({ timeout: 5000 });
      await page.waitForTimeout(6000);
      await settle(page);
      await dismissNotice(page);
      log(`after switch: ${page.url().replace(PORTAL_ORIGIN, '')} | ${await title()} | ${await sub()}`);
    } else {
      log('no entrepreneur entry in the switch menu');
    }

    await page.goto(`${PORTAL_ORIGIN}/remotes-services/tax-payment/pay/add-new`, { waitUntil: 'domcontentloaded', timeout: 45_000 });
    await settle(page);
    await dismissNotice(page);
    await page.waitForTimeout(5000);
    log(`payment form: ${await title()} | ${await page.locator('.ant-select').allInnerTexts().then((a) => JSON.stringify(a.map(SAFE)))}`);

    await page.locator('.ant-select').nth(2).locator('.ant-select-selector').click({ timeout: 4000 });
    await page.waitForTimeout(1500);
    log(`tax types: ${JSON.stringify((await page.locator('.ant-select-dropdown:visible .ant-select-item-option').allInnerTexts()).map(SAFE))}`);
    await page.keyboard.press('Escape');
    await page.screenshot({ path: `${process.env.APPDATA}/bugalter-agent/discovery/pay-probe3.png` }).catch(() => {});
  } catch (error) {
    log(`problem: ${SAFE(error.message)}`);
  } finally {
    await context.close().catch(() => {});
  }
}
