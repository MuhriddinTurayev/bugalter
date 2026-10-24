import { mkdir, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import { createInterface } from 'node:readline/promises';
import { openBrowser } from './browser.js';
import { INSPECT_DIR, PORTAL_ORIGIN } from './config.js';

// Records the STRUCTURE of the signed-in cabinet so a developer can write cabinet-map.json from facts.
// The accountant signs in by hand (E-IMZO). Text inside table cells, values and paragraphs is replaced by its
// length; digits are masked everywhere; only UI words (buttons, links, headings, column titles, labels) are kept.
export const DUMP = () => {
  const UI = new Set(['BUTTON', 'A', 'TH', 'LABEL', 'LEGEND', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'OPTION']);
  const mask = (s) => s.replace(/\d/g, '#');
  const walk = (el, depth) => {
    if (depth > 14 || ['SCRIPT', 'STYLE', 'SVG', 'NOSCRIPT'].includes(el.tagName)) return '';
    const own = [...el.childNodes].filter((n) => n.nodeType === 3).map((n) => n.textContent.trim()).join(' ').replace(/\s+/g, ' ');
    const cls = (el.className?.toString() ?? '').split(' ').filter(Boolean).slice(0, 2).join('.').replace(/__[\w+-]+/g, '');
    const attrs = ['id', 'name', 'type', 'role', 'aria-label', 'data-testid', 'href'].filter((a) => el.getAttribute(a)).map((a) => ` ${a}=${a === 'href' ? mask(el.getAttribute(a).split('?')[0]) : mask(el.getAttribute(a))}`).join('');
    const text = own ? (UI.has(el.tagName) && own.length <= 60 ? ` "${mask(own)}"` : ` [text:${own.length}]`) : '';
    const line = `${'  '.repeat(depth)}<${el.tagName.toLowerCase()}${cls ? '.' + cls : ''}${attrs}>${text}`;
    return [line, ...[...el.children].map((c) => walk(c, depth + 1))].filter(Boolean).join('\n');
  };
  return walk(document.body, 0);
};

export async function inspectCabinet(log = console.log) {
  const { context, page } = await openBrowser();
  await mkdir(INSPECT_DIR, { recursive: true });
  await page.goto(`${PORTAL_ORIGIN}/login?type=legal`);

  log('Sign in by hand with E-IMZO in the browser window.');
  log('Open each page you want mapped (reports list, balances, a report form), then press Enter here to record it.');
  log('Type "q" + Enter to finish.');

  const rl = createInterface({ input: process.stdin, output: process.stdout });
  let n = 0;
  for (;;) {
    const answer = (await rl.question(`[${n + 1}] Enter = record this page, q = quit > `)).trim().toLowerCase();
    if (answer === 'q') break;
    const url = new URL(page.url());
    const file = join(INSPECT_DIR, `${String(++n).padStart(2, '0')}-${url.pathname.replace(/[^\w]+/g, '_').slice(0, 50)}.txt`);
    await writeFile(file, `url: ${url.origin}${url.pathname.replace(/\d{6,}/g, '#')}\n\n${await page.evaluate(DUMP)}\n`);
    log(`recorded ${file}`);
  }

  rl.close();
  await context.close();
  log(`Structure files are in ${INSPECT_DIR}. They contain no amounts, names or TINs.`);
}
