import { mkdir, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import { openBrowser } from './browser.js';
import { loadMap } from './cabinet.js';
import { DISCOVERY_DIR, dismissNotice, settle } from './discover.js';
import { openReporting } from './journal.js';
import { signIn } from './login.js';

// READ-ONLY probe of ONE blank e-reporting form (the turnover-tax form named in cabinet-map.json). It opens the blank form,
// records how the form is built (header selects and their options, the sheet's cells and attributes) and opens a few cell
// edit dialogs to record their markup, closing each with Escape. It never types a value and never presses Save ("Сохранить")
// or Send ("Отправить"): those buttons are not even looked up. Opening a blank form creates nothing on the server.
const SAFE = (s) => String(s ?? '').replace(/\s+/g, ' ').trim().slice(0, 160);

export async function probeForm({ loginType = 'individual', cells = 4, period = null } = {}, log = console.log) {
  await mkdir(DISCOVERY_DIR, { recursive: true });
  const map = await loadMap();
  const { context, page } = await openBrowser();
  const out = { at: new Date().toISOString(), selects: [], sheets: [], dialogs: [], problems: [] };

  try {
    const say = async (kind, message) => log(`${kind}: ${message}`);
    await signIn(page, { tin: '', loginType }, say);
    const reporting = await openReporting(context, page, map.reporting.entry_text[loginType], say);
    await dismissNotice(reporting);

    await reporting.goto(`https://oldmy.soliq.uz${map.reporting.blank_form.url}`, { waitUntil: 'domcontentloaded', timeout: 45_000 });
    await settle(reporting);
    await dismissNotice(reporting);
    log(`form opened: ${new URL(reporting.url()).pathname}`);

    out.selects = await reporting.evaluate(() => [...document.querySelectorAll('#report_form select, select')].map((s) => ({
      id: s.id, name: s.name, disabled: s.disabled, value: s.value,
      options: [...s.options].map((o) => ({ value: o.value, text: o.text.replace(/\s+/g, ' ').trim().slice(0, 60) })).slice(0, 40),
    })));

    out.sheets = await reporting.evaluate(() => [...document.querySelectorAll('table.excel-sheet')].map((t, si) => ({
      sheet: si, id: t.closest('[id^=list-]')?.id ?? null, rows: t.rows.length,
      cells: [...t.querySelectorAll('td')].map((td, i) => ({
        i, row: td.parentElement.rowIndex, col: td.cellIndex, text: td.innerText.replace(/\s+/g, ' ').trim().slice(0, 80),
        cls: td.className, attrs: Object.fromEntries([...td.attributes].filter((a) => a.name !== 'class').map((a) => [a.name, a.value.slice(0, 60)])),
      })).filter((c) => c.text || c.cls || Object.keys(c.attrs).length).slice(0, 400),
    })));
    log(`sheets: ${out.sheets.length}, described cells: ${out.sheets.reduce((n, s) => n + s.cells.length, 0)}`);

    // The header choices a person makes first (tax, kind of calculation, year); read what the period list then offers.
    for (const [id, value] of [['na2_id', '100'], ['type', '1'], ['year', '2026']]) {
      await reporting.selectOption('#' + id, value, { timeout: 4000 }).catch((e) => out.problems.push('select ' + id + ': ' + SAFE(e.message)));
      await reporting.waitForTimeout(600);
    }
    out.periods = await reporting.evaluate(() => [...(document.querySelector('#perioddd')?.options ?? [])].map((o) => ({ value: o.value, text: o.text.replace(/\s+/g, ' ').trim() })));
    if (period) {
      // Choosing the period is part of the form state (nothing is saved); the cabinet may then fill the sheet by itself.
      await reporting.selectOption('#perioddd', period, { timeout: 4000 }).catch((e) => out.problems.push('period: ' + SAFE(e.message)));
      await reporting.waitForTimeout(4000);
      await dismissNotice(reporting);
      out.filled = await reporting.evaluate(() => [...document.querySelectorAll('table.excel-sheet')].map((t) => [...t.rows].map((r) => [...r.cells].map((c) => c.innerText.replace(/\s+/g, ' ').trim().slice(0, 60)).filter(Boolean).join(' | ')).filter(Boolean)));
      await reporting.screenshot({ path: join(DISCOVERY_DIR, 'form-probe-period.png'), fullPage: true }).catch(() => {});
    }
    log('periods offered: ' + JSON.stringify(out.periods));

    // A few editable-looking cells: the ones the form's own markup marks (class/attributes), clicked one at a time.
    const candidates = out.sheets.flatMap((s) => s.cells.filter((c) => /Изменить|Добавить|Редактир/i.test(c.text)).map((c) => ({ ...c, sheet: s.sheet }))).slice(0, cells);
    out.row_labels = out.sheets.map((sh) => Object.values(sh.cells.filter((c) => c.text).reduce((acc, c) => { (acc[c.row] ??= []).push(c.text.slice(0, 70)); return acc; }, {})));
    for (const c of candidates) {
      try {
        await reporting.locator('table.excel-sheet').nth(c.sheet).locator('td').nth(c.i).click({ timeout: 4000, force: true });
        await reporting.waitForTimeout(800);
        const dialog = await reporting.evaluate(() => {
          const el = [...document.querySelectorAll('.modal, [role=dialog]')].find((m) => { const r = m.getBoundingClientRect(); return r.width > 0 && r.height > 0 && getComputedStyle(m).display !== 'none'; });
          if (!el) return null;
          return {
            id: el.id, cls: el.className.slice(0, 120), title: (el.querySelector('.modal-title, h4, h3')?.innerText ?? '').replace(/\s+/g, ' ').trim().slice(0, 120),
            inputs: [...el.querySelectorAll('input, select, textarea')].map((i) => ({ tag: i.tagName, id: i.id, name: i.name, type: i.type, value: String(i.value).slice(0, 40), ph: i.placeholder })),
            buttons: [...el.querySelectorAll('button, a.btn')].map((b) => ({ id: b.id, cls: b.className.slice(0, 80), text: b.innerText.replace(/\s+/g, ' ').trim().slice(0, 40) })),
            text: el.innerText.replace(/\s+/g, ' ').trim().slice(0, 400),
          };
        });
        out.dialogs.push({ cell: { row: c.row, col: c.col, text: c.text, cls: c.cls }, dialog });
        log(`cell ${c.row}:${c.col} -> ${dialog ? `dialog "${dialog.title}" with ${dialog.inputs.length} inputs` : 'no dialog'}`);
        await reporting.screenshot({ path: join(DISCOVERY_DIR, `form-probe-cell-${c.row}-${c.col}.png`) }).catch(() => {});
        await reporting.keyboard.press('Escape');
        await reporting.waitForTimeout(500);
      } catch (error) {
        out.problems.push(`cell ${c.row}:${c.col}: ${SAFE(error.message.split('\n')[0])}`);
      }
    }
    await reporting.screenshot({ path: join(DISCOVERY_DIR, 'form-probe-form.png'), fullPage: true }).catch(() => {});
  } catch (error) {
    out.problems.push(SAFE(error.message));
    log(`problem: ${SAFE(error.message)}`);
  } finally {
    await writeFile(join(DISCOVERY_DIR, 'form-probe.json'), JSON.stringify(out, null, 2));
    await context.close().catch(() => {});
  }

  log(`done: ${out.selects.length} selects, ${out.dialogs.length} dialogs, ${out.problems.length} problems -> ${join(DISCOVERY_DIR, 'form-probe.json')}`);
  return out;
}
