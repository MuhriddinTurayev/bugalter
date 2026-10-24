import { mkdir, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import { openBrowser } from './browser.js';
import { PORTAL_ORIGIN } from './config.js';
import { DISCOVERY_DIR, FORBIDDEN, INFO, dismissNotice, expandMenus, followable, pageKey, settle } from './discover.js';
import { DUMP } from './inspect.js';
import { isLoggedIn, isReportingOpen } from './login.js';

// READ-ONLY discovery of the official e-reporting system (oldmy.soliq.uz, "wefo4-clientui"), reached from the cabinet's
// own service links. The accountant signs in by hand. The agent finds the reporting links on the services page itself
// (no URL is hard-coded), follows them, records what the reporting system offers (modules, menu items, tabs, tables,
// form fields and action buttons by name) and clicks only navigation: menu items and tabs, never a button that saves,
// signs, sends, pays or deletes. Digits are masked and cell/paragraph text is replaced by its length.

const NAV = '[role=menuitem], [role=tab], nav a, .nav-tabs a, .ant-tabs-tab, .ant-menu-item, .menu a, ul.menu li';

/** Candidate reporting-system links on the services page: text + full href, read at run time. */
export async function findReportingLinks(page) {
  return page.evaluate(() => [...document.querySelectorAll('a[href]')]
    .map((a) => ({ text: a.innerText.replace(/\s+/g, ' ').trim().slice(0, 120), href: a.getAttribute('href') }))
    .filter((l) => /oldmy\.soliq\.uz|wefo|redirect-to/i.test(l.href)));
}

export async function discoverReporting({ loginType = 'individual', maxPages = 30 } = {}, log = console.log) {
  await mkdir(DISCOVERY_DIR, { recursive: true });
  const { context, page: first } = await openBrowser();
  const report = { started_at: new Date().toISOString(), login_type: loginType, links: [], entries: [], pages: [], popups: [], problems: [] };
  const popups = [];
  context.on('page', (p) => popups.push(p));

  try {
    await first.goto(`${PORTAL_ORIGIN}/login?type=${loginType}`, { waitUntil: 'domcontentloaded' });
    log("Brauzerda o'zingiz kiring (kalit, E-IMZO paroli, CAPTCHA/MFA). 10 daqiqa kutaman.");
    const deadline = Date.now() + 10 * 60_000;
    while (!isLoggedIn(first.url())) {
      if (Date.now() > deadline || first.isClosed()) throw new Error('Kirish yakunlanmadi.');
      await first.waitForTimeout(1000);
    }
    await settle(first);
    log('Kirildi. Hisobot tizimiga havolalarni qidiraman.');

    await first.goto(`${PORTAL_ORIGIN}/with-sidebar/services`, { waitUntil: 'domcontentloaded' });
    await settle(first);
    const all = await findReportingLinks(first);
    report.links = all.map((l) => ({ text: l.text, href: l.href.replace(/\d{4,}/g, '#') }));
    // Entry links are chosen by what they open (the reporting application), not by their wording: "submit reports" in a
    // link's name is the service's title. Inside the application the FORBIDDEN filter still applies to every control.
    const targets = all.filter((l) => /wefo4-clientui/i.test(l.href)).sort((a, b) => Number(/Жисмоний/.test(b.text)) - Number(/Жисмоний/.test(a.text)));
    log(`Hisobot tizimiga ${all.length} ta havola, shulardan hisobot nomlilari: ${targets.length}`);
    for (const t of targets) log(`  • ${t.text}`);

    const seenEntries = new Set();
    for (const target of targets) {
      const url = followable(target.href, '');
      if (!url || seenEntries.has(pageKey(url))) continue;
      seenEntries.add(pageKey(url));
      await visit(context, first, url, target.text, report, popups, log, maxPages);
    }
  } catch (error) {
    report.problems.push({ step: 'run', error: error.message });
    log(`Muammo: ${error.message}`);
  } finally {
    report.finished_at = new Date().toISOString();
    await writeFile(join(DISCOVERY_DIR, 'reporting.json'), JSON.stringify(report, null, 2));
    await context.close().catch(() => {});
  }

  log(`Tayyor: ${report.entries.length} kirish nuqtasi, ${report.pages.length} sahifa, ${report.problems.length} muammo → ${DISCOVERY_DIR}\\reporting.json`);
  return report;
}

async function visit(context, page, url, title, report, popups, log, maxPages) {
  const before = popups.length;
  log(`→ ${title}`);
  // As a person does: open the services page and click the service's card (a plain navigation link). The site then
  // opens the reporting system, usually in a new tab with a single-sign-on token that a direct URL does not carry.
  await page.goto(`${PORTAL_ORIGIN}/with-sidebar/services`, { waitUntil: 'domcontentloaded', timeout: 45_000 });
  await settle(page);
  try {
    await page.locator('a[href*="wefo4-clientui"]').filter({ hasText: title.slice(0, 25) }).first().click({ timeout: 10_000 });
  } catch (error) {
    report.problems.push({ step: title, error: `The service card could not be clicked: ${error.message.split('\n')[0]}` });
  }

  // The service link may redirect, or open a new tab.
  const landing = await waitForReporting(page, popups, before, log);
  const entry = { title, landed: landing ? pageKey(new URL(landing.url())) : null, via: landing && landing !== page ? 'new tab' : 'same tab', final_host: landing ? new URL(landing.url()).host : new URL(page.url()).host };
  report.entries.push(entry);

  if (!landing) {
    report.problems.push({ step: title, error: `The reporting system did not open (stayed on ${new URL(page.url()).host}${new URL(page.url()).pathname}).` });
    await page.screenshot({ path: join(DISCOVERY_DIR, `wefo-problem-${report.entries.length}.png`) }).catch(() => {});
    return;
  }
  log(`  ochildi: ${entry.final_host}${new URL(landing.url()).pathname}${landing !== page ? ' (yangi tab)' : ''}`);

  await settle(landing);
  await record(landing, report, log, `entry ${report.entries.length}`);

  // Navigation only: menu items and tabs of the reporting system, each visited once.
  const labels = await landing.evaluate((sel) => [...document.querySelectorAll(sel)]
    .filter((e) => { const r = e.getBoundingClientRect(); return r.width > 0 && r.height > 0; })
    .map((e) => e.innerText.replace(/\s+/g, ' ').trim()).filter((t) => t && t.length < 60), NAV);
  const unique = [...new Set(labels)].filter((t) => !FORBIDDEN.test(t)).slice(0, 20);
  report.entries.at(-1).navigation = unique.map((t) => t.replace(/\d/g, '#'));

  await exploreReports(landing, report, log);

  for (const label of unique) {
    if (report.pages.length >= maxPages) break;
    try {
      await expandMenus(landing);
      const item = landing.locator(NAV).filter({ hasText: label }).first();
      await item.click({ timeout: 4000 });
      await settle(landing);
      if (!isReportingOpen(landing.url()) && !isLoggedIn(landing.url())) throw new Error(`Left the reporting system (${new URL(landing.url()).host}).`);
      await record(landing, report, log, label);
    } catch (error) {
      report.problems.push({ step: label.replace(/\d/g, '#'), error: error.message.split('\n')[0] });
    }
  }
}

/**
 * After the journal: read-only look at what a report is and what the system offers. Opens (by their links, as read at
 * run time) one existing report's view page, its confirmation page (the receipt) and the catalog of electronic forms.
 * It never opens a "create" or "send" page: only the catalog's listing pages, whose names are recorded.
 */
async function exploreReports(page, report, log) {
  const links = await page.evaluate(() => [...document.querySelectorAll('a[href]')].map((a) => ({ text: a.innerText.replace(/\s+/g, ' ').trim().slice(0, 80), href: a.getAttribute('href') })));
  const pick = (re) => links.find((l) => re.test(l.href));
  const steps = [
    ['report-view', pick(/\/report\/[^/]+\/get\/[0-9A-F]+\/view/i)],
    ['confirmation', pick(/\/report\/[^/]+\/confirmation\/[0-9A-F]+\/view/i)],
    ['catalog', pick(/catalog\/ru\/get\/package_groups/)],
  ];
  const journal = page.url();

  for (const [label, link] of steps) {
    if (!link) { report.problems.push({ step: label, error: 'No such link on the journal page.' }); continue; }
    const url = followable(new URL(link.href, page.url()).href, '');
    if (!url) { report.problems.push({ step: label, error: 'The link is not a followable official page.' }); continue; }
    try {
      await page.goto(url.href, { waitUntil: 'domcontentloaded', timeout: 45_000 });
      await settle(page);
      await dismissNotice(page);
      if (!isReportingOpen(page.url())) throw new Error(`Left the reporting system (${new URL(page.url()).host}).`);
      await record(page, report, log, label);

      if (label === 'catalog') {
        // The catalog's listing pages (groups of forms), by their own links; names and addresses only.
        const groups = (await page.evaluate(() => [...document.querySelectorAll('a[href]')].map((a) => ({ text: a.innerText.replace(/\s+/g, ' ').trim().slice(0, 80), href: a.getAttribute('href') }))))
          .filter((l) => /catalog\/ru\/get\//.test(l.href) && !/package_groups$/.test(l.href)).slice(0, 8);
        report.catalog = groups.map((g) => ({ text: g.text.replace(/\d/g, '#'), href: g.href.replace(/\d{4,}/g, '#') }));
        for (const g of groups.slice(0, 4)) {
          const gu = followable(new URL(g.href, page.url()).href, '');
          if (!gu) continue;
          await page.goto(gu.href, { waitUntil: 'domcontentloaded', timeout: 45_000 });
          await settle(page);
          await record(page, report, log, 'group-' + g.text.slice(0, 20));
          const formLink = (await page.evaluate(() => [...document.querySelectorAll('a[href]')].map((a) => a.getAttribute('href')))).find((h) => /\/report\/[0-9]+_[0-9]+$/.test(h));
          if (formLink && !report.blank_form) report.blank_form = formLink;
        }
      }
    } catch (error) {
      report.problems.push({ step: label, error: error.message.split('\n')[0] });
      await page.screenshot({ path: join(DISCOVERY_DIR, `wefo-problem-${label}.png`) }).catch(() => {});
    }
    await page.goto(journal, { waitUntil: 'domcontentloaded' }).catch(() => {});
    await settle(page);
  }

  // The blank form of the form code the journal already uses. Opened and READ only: nothing is typed or pressed. The
  // drafts pane is counted before and after, to prove that merely opening a form creates nothing on the server.
  if (report.blank_form) {
    try {
      report.drafts_before = await countDrafts(page);
      const url = followable(new URL(report.blank_form, page.url()).href, '');
      if (!url) throw new Error('The form link is not a followable official page.');
      await page.goto(url.href, { waitUntil: 'domcontentloaded', timeout: 45_000 });
      await settle(page);
      await dismissNotice(page);
      await record(page, report, log, 'blank-form');
      await page.goto(journal, { waitUntil: 'domcontentloaded' });
      await settle(page);
      report.drafts_after = await countDrafts(page);
      log(`  qoralamalar: ${report.drafts_before} -> ${report.drafts_after}`);
    } catch (error) {
      report.problems.push({ step: 'blank-form', error: error.message.split('\n')[0] });
      await page.screenshot({ path: join(DISCOVERY_DIR, 'wefo-problem-blank-form.png') }).catch(() => {});
    }
  }
}

/** Rows in the journal's drafts pane: used to prove that opening a blank form creates nothing on the server. */
async function countDrafts(page) {
  return page.locator('#div_draft_reports tbody tr:not(.ant-table-placeholder):not(.ant-table-measure-row)').count().catch(() => null);
}

async function waitForReporting(page, popups, before, log) {
  const deadline = Date.now() + 40_000;
  while (Date.now() < deadline) {
    for (const p of [page, ...popups.slice(before)]) {
      if (p.isClosed()) continue;
      if (isReportingOpen(p.url())) return p;
      if (await dismissNotice(p)) log && log('  axborot oynasi yopildi');
    }
    await page.waitForTimeout(500);
  }
  return null;
}

async function record(page, report, log, label) {
  const info = await page.evaluate(INFO);
  const frames = [];
  for (const frame of page.frames().slice(1, 6)) {
    if (!/soliq\.uz/.test(frame.url())) continue;
    frames.push({ url: pageKey(new URL(frame.url())), ...(await frame.evaluate(INFO).catch(() => ({}))) });
  }

  const index = String(report.pages.length + 1).padStart(2, '0');
  const key = pageKey(new URL(page.url()));
  const safe = `${label}`.replace(/[^\p{L}\p{N}]+/gu, '_').slice(0, 40);
  await writeFile(join(DISCOVERY_DIR, `wefo-${index}-${safe}.txt`), `url: ${key}\nlabel: ${label.replace(/\d/g, '#')}\n\n${await page.evaluate(DUMP)}\n`);
  await page.screenshot({ path: join(DISCOVERY_DIR, `wefo-${index}-${safe}.png`) }).catch(() => {}); // local only
  report.pages.push({ label: label.replace(/\d/g, '#'), key, ...info, frames });
  log(`  [${index}] ${key} (${info.tables.length} jadval, ${info.fields.length} maydon, ${info.buttons.length} tugma, ${frames.length} freym)`);
}
