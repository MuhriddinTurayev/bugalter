import { openBrowser } from './browser.js';
import { loadMap } from './cabinet.js';
import { openReporting } from './journal.js';
import { signIn } from './login.js';
import { readTurnover } from './turnover.js';

/** `npm run form -- 2026 9`: signs in, opens the turnover form, chooses the period and prints the cabinet's own figures. Nothing is saved or sent. */
export async function readOnly({ year, month }, log = console.log) {
  if (!Number.isInteger(year) || !Number.isInteger(month)) throw new Error('Usage: node src/cli.js form <year> <month>');

  const map = await loadMap();
  const { context, page } = await openBrowser();
  try {
    const say = async (kind, message) => log(`${kind}: ${message}`);
    await signIn(page, { tin: '', loginType: 'individual' }, say);
    const reporting = await openReporting(context, page, map.reporting.entry_text.individual, say);
    const parsed = await readTurnover(reporting, map, { year, month }, say);
    log(JSON.stringify({ ...parsed, tin: parsed.tin.replace(/\d(?=\d{3})/g, '*') }, null, 2));
  } finally {
    await context.close().catch(() => {});
  }
}
