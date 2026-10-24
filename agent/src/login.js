import { PORTAL_ORIGIN } from './config.js';

// Selectors below were taken from the real https://my.soliq.uz/login page (classes are CSS-module names with a hash
// suffix, so only the stable prefix is matched). E-IMZO signs in the browser itself over wss://127.0.0.1:64443; the
// agent only clicks "Кириш" on the key card of the company and waits while the accountant answers E-IMZO.
const TABS = { legal: 'Юридик шахс', individual: 'Жисмоний шахс', entrepreneur: 'Якка тартибдаги тадбиркор', self_employed: 'Ўзини банд қилган шахс' };
const CARDS = '[class*="KeyCard_key-card"]';
const ENTER = 'Кириш';

export const LOGIN_TIMEOUT_MS = 6 * 60_000;

/** True when the page is no longer the login page: the cabinet is open. */
export function isLoggedIn(url) {
  const u = new URL(url);
  return u.origin === PORTAL_ORIGIN && !u.pathname.startsWith('/login');
}

/** On the reporting system (oldmy.soliq.uz) the session is open unless the page is a sign-in / SSO page. */
export function isReportingOpen(url) {
  const u = new URL(url);
  return u.origin === 'https://oldmy.soliq.uz' && !new RegExp('[/#](login|sso|auth|signin)(\\b|/|$)', 'i').test(u.pathname + u.hash);
}

/** The key cards whose text names the company's TIN. Pure, so it can be tested without a browser. */
export function matchingCards(cardTexts, tin) {
  return cardTexts.map((text, index) => ({ text, index })).filter((card) => card.text.replace(/\D/g, '').includes(tin)).map((card) => card.index);
}

/**
 * Signs in with the official E-IMZO flow. `say(kind, message, waitingFor)` reports progress to Laravel; it throws
 * when the task was cancelled. Returns once the cabinet is open, or throws with a reason a person can act on.
 */
export async function signIn(page, { tin, loginType = 'legal' }, say) {
  await say('progress', 'Opening my.soliq.uz');
  await page.goto(`${PORTAL_ORIGIN}/login?type=${encodeURIComponent(loginType)}`, { waitUntil: 'domcontentloaded', timeout: 45_000 });

  // A saved session makes the site leave the login page a moment after it loads; a fresh one lists the key cards.
  // Whichever happens first decides; the cards are never searched for while the cabinet is on its way.
  const cards = page.locator(CARDS);
  const settled = Date.now() + 30_000;
  let tabClicked = false;
  while (Date.now() < settled) {
    if (isLoggedIn(page.url())) {
      await say('progress', 'The cabinet was already open');
      return;
    }
    if (!tabClicked) {
      tabClicked = await page.locator(TABS_SELECTOR(loginType)).first().click({ timeout: 1500 }).then(() => true).catch(() => false);
    }
    if ((await cards.count()) > 0) break;
    await page.waitForTimeout(500);
  }

  // The site lists the keys E-IMZO found on this computer. No cards: E-IMZO is not running or has no keys.
  if ((await cards.count()) === 0) {
    throw new Error('The site shows no ERI keys. Start E-IMZO on this computer, and check that the key is in the listed folder.');
  }

  const texts = await cards.allInnerTexts();
  const hits = matchingCards(texts, tin);

  if (hits.length === 1) {
    await say('progress', 'Selecting the key of the company');
    await cards.nth(hits[0]).getByRole('button', { name: ENTER }).click({ timeout: 10_000 });
  } else {
    // Zero or several keys name this TIN: the agent never guesses which key to use.
    await say('needs_user', hits.length === 0 ? 'Select the key of this company in the browser window' : 'Several keys match: select the right one in the browser window', 'key_selection');
  }

  await say('needs_user', 'Enter the key password in the E-IMZO window', 'eimzo_password');
  await waitForCabinet(page, say);
  await say('progress', 'Signed in');
}

const TABS_SELECTOR = (type) => `[class*="Auth_main_keysCard_buttons"] button:has-text("${TABS[type] ?? TABS.legal}")`;

async function waitForCabinet(page, say) {
  const deadline = Date.now() + LOGIN_TIMEOUT_MS;
  let lastPing = 0;

  while (Date.now() < deadline) {
    if (isLoggedIn(page.url())) return;
    if (page.isClosed()) throw new Error('The browser window was closed before sign-in finished.');

    // Keeps the lease alive and notices a cancellation by the accountant.
    if (Date.now() - lastPing > 20_000) {
      await say('needs_user', 'Waiting for sign-in: answer the E-IMZO window or the page', 'eimzo_password');
      lastPing = Date.now();
    }
    await page.waitForTimeout(1000);
  }

  throw new Error('Sign-in was not completed in time.');
}
