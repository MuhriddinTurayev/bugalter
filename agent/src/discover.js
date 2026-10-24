import { mkdir, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import { openBrowser } from './browser.js';
import { DIR, PORTAL_ORIGIN } from './config.js';
import { isLoggedIn } from './login.js';
import { DUMP } from './inspect.js';

export const DISCOVERY_DIR = join(DIR, 'discovery');

// READ-ONLY crawl of the signed-in cabinet. The accountant signs in by hand (E-IMZO password, CAPTCHA, MFA).
// Afterwards the agent only follows plain navigation links and menu expanders. It never presses a button, never types,
// never opens payment, sign, send, delete or sign-out controls. Output keeps UI words only: digits are masked and the
// text of cells and paragraphs is replaced by its length, so no amounts, names or TINs are written.

/** Controls the crawler must never activate, in any language the cabinet uses. */
export const FORBIDDEN = /export|download|redirect4pay|чиқиш|выход|logout|sign.?out|ўчир|удал|delete|имзол|подпис|sign\b|юбор|отправ|топшир|тўл|оплат|pay\b|тасдиқла|подтверд|сақла|сохран|save|ariza.*(yubor|qabul)/i;

/** The official hosts the cabinet and its reporting system live on. Nothing else is ever followed. */
export const OFFICIAL_ORIGINS = [PORTAL_ORIGIN, 'https://oldmy.soliq.uz'];

/** Links worth following: the cabinet's own pages (and its reporting system) only. */
export function followable(href, text, origin = PORTAL_ORIGIN) {
  if (!href || href.startsWith('#') || /^(mailto|tel|javascript):/i.test(href)) return null;
  let url;
  try {
    url = new URL(href, origin);
  } catch {
    return null;
  }
  if (!OFFICIAL_ORIGINS.includes(url.origin)) return null;
  if (url.pathname.startsWith('/login') || /\/files\//.test(url.pathname)) return null;
  if (FORBIDDEN.test(text) || FORBIDDEN.test(url.pathname)) return null;
  return url;
}

/** A path with long ids replaced, so two pages of the same kind are one page kind. */
export const pageKey = (url) => {
  const path = url.pathname.replace(/[0-9A-F]{32}/g, '{id}').replace(new RegExp('/' + '\\d{4,}', 'g'), '/#').replace(/[0-9a-f]{8}-[0-9a-f-]{27}/g, '/{uuid}');
  const hash = url.hash.startsWith('#/') ? url.hash.replace(new RegExp('\\d{4,}', 'g'), '#') : '';
  return (url.origin === PORTAL_ORIGIN ? '' : url.origin) + path + hash;
};

export const INFO = () => {
  const mask = (s) => s.replace(/\d/g, '#').replace(/\s+/g, ' ').trim();
  const vis = (el) => { const r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0; };
  const label = (el) => {
    const id = el.id && document.querySelector(`label[for="${CSS.escape(el.id)}"]`);
    return mask((id?.innerText ?? el.closest('label')?.innerText ?? el.getAttribute('aria-label') ?? el.placeholder ?? '').slice(0, 80));
  };

  // Where does the page show a taxpayer identifier? Position only, never the number.
  const idSpots = [];
  const tw = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  for (let n = tw.nextNode(); n && idSpots.length < 6; n = tw.nextNode()) {
    const m = n.textContent.match(/\b(\d{9}|\d{14})\b/);
    if (!m || !n.parentElement || !vis(n.parentElement)) continue;
    const el = n.parentElement;
    const path = [];
    for (let e = el, i = 0; e && e !== document.body && i < 5; e = e.parentElement, i++) path.unshift(`${e.tagName.toLowerCase()}${e.className?.toString() ? '.' + e.className.toString().split(' ')[0] : ''}`);
    idSpots.push({ digits: m[1].length, tag: el.tagName.toLowerCase(), path: path.join(' > '), label: mask((el.previousElementSibling?.innerText ?? el.parentElement?.previousElementSibling?.innerText ?? '').slice(0, 60)), sameTextWords: mask(n.textContent).slice(0, 60) });
  }

  return {
    title: document.title,
    headings: [...document.querySelectorAll('h1,h2,h3,h4')].filter(vis).map((h) => mask(h.innerText).slice(0, 100)).slice(0, 30),
    links: [...document.querySelectorAll('a[href]')].filter(vis).map((a) => ({ text: mask(a.innerText).slice(0, 80), href: a.getAttribute('href') })).slice(0, 400),
    buttons: [...document.querySelectorAll('button, [role=button]')].filter(vis).map((b) => ({ text: mask(b.innerText).slice(0, 60), expanded: b.getAttribute('aria-expanded'), disabled: b.disabled === true })).slice(0, 120),
    tables: [...document.querySelectorAll('table, [role=table], .ant-table')].filter(vis).slice(0, 8).map((t) => ({
      headers: [...t.querySelectorAll('th, [role=columnheader]')].map((h) => mask(h.innerText).slice(0, 60)),
      rows: t.querySelectorAll('tbody tr, [role=row]').length,
      className: (t.className?.toString() ?? '').split(' ')[0],
      // Vocabulary of status/type columns only (never names, ids, amounts or dates): the words the site uses.
      columnWords: (() => {
        const heads = [...t.querySelectorAll('thead th, [role=columnheader]')].map((h) => h.innerText.trim());
        const out = {};
        heads.forEach((h, i) => {
          if (!/ҳолат|статус|состояни|holat|тури|turi|тип|наименован|период/i.test(h)) return;
          const words = new Set();
          t.querySelectorAll('tbody tr').forEach((tr) => { const c = tr.children[i]?.innerText?.trim(); if (c && c.length <= 60 && !/\d{3,}/.test(c)) words.add(c); });
          out[mask(h).slice(0, 40)] = [...words].slice(0, 8);
        });
        return out;
      })(),
    })),
    fields: [...document.querySelectorAll('input, select, textarea')].filter(vis).map((f) => ({ tag: f.tagName.toLowerCase(), type: f.type || null, name: f.name || null, id: f.id || null, label: label(f), required: f.required, readOnly: f.readOnly, hasValue: Boolean(f.value) })).slice(0, 80),
    idSpots,
  };
};

export async function discoverCabinet({ loginType = 'individual', maxPages = 40, seeds = [], follow = true } = {}, log = console.log) {
  await mkdir(DISCOVERY_DIR, { recursive: true });
  const { context, page } = await openBrowser();
  const seen = new Map();
  const report = { started_at: new Date().toISOString(), login_type: loginType, pages: [], skipped: [], problems: [] };

  try {
    await page.goto(`${PORTAL_ORIGIN}/login?type=${loginType}`, { waitUntil: 'domcontentloaded' });
    log('Brauzerda o\'zingiz kiring: kalitni tanlang, E-IMZO oynasida parolni kiriting, CAPTCHA/MFA bo\'lsa bajaring. 10 daqiqa kutaman.');

    const deadline = Date.now() + 10 * 60_000;
    while (!isLoggedIn(page.url())) {
      if (Date.now() > deadline || page.isClosed()) throw new Error('Kirish yakunlanmadi.');
      await page.waitForTimeout(1000);
    }
    await settle(page);
    log(`Kirildi. Faqat o'qiyman: ${new URL(page.url()).pathname}`);

    // With explicit seeds only those pages are read (and links are not followed unless asked).
    const queue = seeds.length ? seeds.map((p) => followable(p, '')).filter(Boolean) : [new URL(page.url())];
    while (queue.length && report.pages.length < maxPages) {
      const url = queue.shift();
      const key = pageKey(url);
      if (seen.has(key)) continue;
      seen.set(key, true);

      try {
        if (pageKey(new URL(page.url())) !== key) await page.goto(url.href, { waitUntil: 'domcontentloaded', timeout: 45_000 });
        await settle(page);
        if (!isLoggedIn(page.url())) throw new Error('Sessiya tugadi yoki sahifa kirishga qaytardi.');
        await expandMenus(page);

        const info = await page.evaluate(INFO);
        const index = String(report.pages.length + 1).padStart(2, '0');
        const safe = key.replace(/[^\w]+/g, '_').slice(0, 50);
        await writeFile(join(DISCOVERY_DIR, `${index}-${safe}.txt`), `url: ${PORTAL_ORIGIN}${key}\n\n${await page.evaluate(DUMP)}\n`);
        // Local only: may show company data. Never sent to Laravel, never printed.
        await page.screenshot({ path: join(DISCOVERY_DIR, `${index}-${safe}.png`), fullPage: false }).catch(() => {});

        report.pages.push({ key, ...info });
        log(`[${index}] ${key}  (${info.links.length} havola, ${info.tables.length} jadval, ${info.fields.length} maydon)`);

        if (!follow) continue;
        for (const link of info.links) {
          const next = followable(link.href, link.text);
          if (next && !seen.has(pageKey(next))) queue.push(next);
          else if (!next && link.href && !link.href.startsWith('#')) report.skipped.push({ text: link.text, href: link.href.split('?')[0] });
        }
      } catch (error) {
        report.problems.push({ page: key, error: error.message });
        log(`  muammo: ${key}: ${error.message}`);
        await page.screenshot({ path: join(DISCOVERY_DIR, `problem-${report.problems.length}.png`) }).catch(() => {});
        if (!isLoggedIn(page.url())) break;
      }
    }
  } finally {
    report.finished_at = new Date().toISOString();
    await writeFile(join(DISCOVERY_DIR, 'discovery.json'), JSON.stringify(report, null, 2));
    await context.close().catch(() => {});
  }

  log(`Tayyor: ${report.pages.length} sahifa, ${report.problems.length} muammo → ${DISCOVERY_DIR}`);
  return report;
}

export async function settle(page) {
  await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {});
  // Ant Design spinners / skeletons
  await page.locator('.ant-spin-spinning, .ant-skeleton-active').first().waitFor({ state: 'hidden', timeout: 10_000 }).catch(() => {});
}

/** Opens collapsed menu sections (aria-expanded=false) so their links become visible. Nothing else is clicked. */
export async function expandMenus(page) {
  for (let round = 0; round < 3; round++) {
    const closed = page.locator('nav [aria-expanded="false"], aside [aria-expanded="false"], [class*="Sidebar"] [aria-expanded="false"], [class*="Navbar"] [aria-expanded="false"]');
    const n = await closed.count();
    if (!n) return;
    for (let i = 0; i < Math.min(n, 12); i++) {
      const el = closed.nth(i);
      const text = ((await el.innerText().catch(() => '')) || '').trim();
      if (FORBIDDEN.test(text)) continue;
      await el.click({ timeout: 2000 }).catch(() => {});
    }
    await page.waitForTimeout(400);
  }
}

/**
 * Closes a purely informational notice (a modal whose only control is its close button). A modal with any other
 * button, link or field is a question or a form: it is left alone for a person. Returns true when one was closed.
 */
export async function dismissNotice(page) {
  try {
    const modal = page.locator('.ant-modal-wrap:visible').first();
    if (!(await modal.count())) return false;

    const controls = await modal.locator('button, a[href], input, select, textarea, [role=button]').evaluateAll((els) => els.map((e) => ({
      close: /ant-modal-close|close/i.test(e.className?.toString() ?? '') || /close|закры|ёп|×|✕/i.test((e.getAttribute('aria-label') ?? '') + e.innerText),
    })));
    if (controls.length === 0 || controls.some((c) => !c.close)) return false;

    await modal.locator('.ant-modal-close, [aria-label*=lose i], button:has-text("×")').first().click({ timeout: 2000 });
    await page.waitForTimeout(500);
    return true;
  } catch {
    return false;
  }
}
