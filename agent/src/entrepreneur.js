import { settle } from './discover.js';

// An individual entrepreneur signs in to my.soliq.uz with the same E-IMZO key as a private person and lands in the PRIVATE
// PERSON's cabinet. The entrepreneur's own cabinet ("ЯТТнинг шахсий кабинети") is one click away: the header's switch button
// ("Ўтиш") offers it (observed 2026-10-24; it calls move/entrepreneur/token and nothing else changes). Debts, the demands of the
// tax authority, the payment form and the tax types all live there, so the agent switches to it before it reads or pays anything.
const IN_ENTREPRENEUR = /ЯТТнинг шахсий кабинети|YaTTning shaxsiy kabineti|кабинет ИП/i;
const SWITCH = /^(Ўтиш|O.tish|Переход|Перейти)$/;
const ENTREPRENEUR_ITEM = /Якка тартибдаги тадбиркор|Yakka tartibdagi tadbirkor|индивидуальн/i;

const headerText = (page) => page.locator('header, [class*="Header_host_header"]').first().innerText({ timeout: 4000 }).catch(() => '');

export async function inEntrepreneurCabinet(page) {
  return IN_ENTREPRENEUR.test(await headerText(page));
}

/** Switches to the entrepreneur's cabinet (a navigation: nothing is changed on the account). Throws when it cannot. */
export async function switchToEntrepreneur(page, say = async () => {}) {
  if (await inEntrepreneurCabinet(page)) return;

  await say('progress', "Switching to the entrepreneur's own cabinet");
  await page.locator('button', { hasText: SWITCH }).first().click({ timeout: 10_000 });
  await page.waitForTimeout(700);
  await page.locator('.ant-dropdown:visible li, [role=menuitem]:visible, .ant-dropdown:visible a', { hasText: ENTREPRENEUR_ITEM }).first().click({ timeout: 10_000 });

  for (let i = 0; i < 40; i++) {
    await page.waitForTimeout(1000);
    if (await inEntrepreneurCabinet(page)) {
      await settle(page);
      return;
    }
  }

  throw new Error("The entrepreneur's cabinet did not open after the switch. Nothing was read or paid.");
}
