import assert from 'node:assert/strict';
import { test } from 'node:test';
import { chromium } from 'playwright-core';
import { findBrowser } from '../src/browser.js';
import { PANES, periodOf, readJournal, toSnapshot, typeOf } from '../src/journal.js';

// The journal's markup as observed on oldmy.soliq.uz/wefo4-clientui/form/ru/reports/0: one tab-pane per state, each
// with a Bootstrap table; only the first pane is displayed. Header words are the real ones.
const HEAD = ['Действие', '№ Отчета', '№ Пакет', 'Наименование', 'Год', 'Период', 'Дата отправки', 'Сертификат', 'Состояние', 'Дата проверки', 'Инспектор', 'Регион', 'Дата создания', 'IP адрес'];
const row = (o) => `<tr>${HEAD.map((h) => `<td>${{ '№ Отчета': o.id, 'Наименование': o.form, 'Год': o.year, 'Период': o.period, 'Дата отправки': o.sent, 'Состояние': o.state }[h] ?? ''}</td>`).join('')}</tr>`;
const pane = (id, rows, shown = false) => `<div id="${id}" class="tab-pane" style="display:${shown ? 'block' : 'none'}"><div class="table-responsive"><table class="table"><thead><tr>${HEAD.map((h) => `<th>${h}</th>`).join('')}</tr></thead><tbody>${rows.map(row).join('')}</tbody></table></div></div>`;

const page = (panes) => `<div class="tab-content">${Object.keys(PANES).map((id) => pane(id, panes[id] ?? [], id === 'div_processing_reports')).join('')}</div>`;

async function withPage(html, fn) {
  const browser = await chromium.launch({ channel: findBrowser(), headless: true });
  try {
    const p = await browser.newPage();
    await p.setContent(html);
    return await fn(p);
  } finally {
    await browser.close();
  }
}

const FORM_TYPES = { 'налога с оборота': 'turnover', 'налога на прибыль': 'profit' };

test('every pane of the journal is read, hidden ones included, and mapped to a status', async () => {
  const html = page({
    div_approved_reports: [
      { id: '9001', form: 'Расчет налога с оборота', year: '2026', period: 'Сентябрь', sent: '12.10.2026 10:15', state: 'Проведенный\nс опозданием' },
      { id: '9002', form: 'Расчет налога с оборота', year: '2026', period: 'Август', sent: '12.09.2026 09:00', state: 'Проведенный\nс опозданием' },
    ],
    div_error_reports: [{ id: '9003', form: 'Расчет налога на прибыль', year: '2026', period: 'Июль', sent: '01.08.2026', state: 'Ошибка' }],
    div_draft_reports: [{ id: '9004', form: 'Расчет налога с оборота', year: '2026', period: 'Октябрь', sent: '', state: 'Черновик' }],
  });

  const rows = await withPage(html, (p) => readJournal(p, { formTypes: FORM_TYPES }));

  assert.equal(rows.length, 4);
  const byNumber = Object.fromEntries(rows.map((r) => [r.report_number, r]));
  assert.deepEqual(
    [byNumber['9001'].status, byNumber['9001'].type, byNumber['9001'].period_start, byNumber['9001'].period_end, byNumber['9001'].sent_at, byNumber['9001'].state_text],
    ['accepted', 'turnover', '2026-09-01', '2026-09-30', '2026-10-12', 'Проведенный с опозданием'],
  );
  assert.equal(byNumber['9002'].period_end, '2026-08-31');
  assert.deepEqual([byNumber['9003'].status, byNumber['9003'].type], ['rejected', 'profit']);
  assert.deepEqual([byNumber['9004'].status, byNumber['9004'].sent_at], ['draft', null]);
});

test('an empty journal is an empty list', async () => {
  assert.deepEqual(await withPage(page({}), (p) => readJournal(p, { formTypes: FORM_TYPES })), []);
});

test('a journal that lost a column stops instead of reading the wrong one', async () => {
  const html = page({}).replaceAll('<th>Состояние</th>', '<th>Что-то другое</th>');
  await assert.rejects(withPage(html, (p) => readJournal(p, { formTypes: FORM_TYPES })), /no "state" column/);
});

test('periods and forms that were not observed are null, never guessed', () => {
  assert.deepEqual(periodOf('Февраль', '2024'), { start: '2024-02-01', end: '2024-02-29' }); // leap year
  assert.equal(periodOf('1 квартал', '2026'), null);
  assert.equal(periodOf('Сентябрь', 'abcd'), null);
  assert.equal(typeOf('Расчет налога с оборота', FORM_TYPES), 'turnover');
  assert.equal(typeOf('Какая-то новая форма', FORM_TYPES), null);
});

test('the snapshot carries only what the journal showed', () => {
  const snap = toSnapshot([{ type: 'turnover', form: 'F', period_start: '2026-09-01', period_end: '2026-09-30', status: 'accepted', sent_at: '2026-10-12', report_number: '9001' }], { tin: '62510026590062', name: null });
  assert.equal(snap.reports[0].receipt, '9001');
  assert.deepEqual(snap.balances, []);
  assert.equal(snap.taxpayer.tin, '62510026590062');
});
