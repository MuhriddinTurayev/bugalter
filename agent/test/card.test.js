import assert from 'node:assert/strict';
import { test } from 'node:test';
import { parseCard, splitTaxType } from '../src/card.js';

// Rows as the personal tax card showed them on 2026-10-24 (the entrepreneur's cabinet), shortened; the later-dated rows are the
// cabinet's own forecast of future accruals and penalty and must never be counted.
const HEAD = ['№', 'Операция санаси', 'Операция мазмуни', 'Тўлов муддати', 'Тўлашга ҳисобланган сумма', 'Камайтирилган сумма', 'Тўланган сумма', 'Қайтарилган сумма', 'Солиқ қарзи', 'Ортиқча тўлов', 'Устама суммаси', 'Устама ҳисобланган давр', 'Тўланган устама суммаси', 'Тўланмаган устама қолдиғи', ''];
const row = (n, date, what, due, accrued, paid, returned, debt, over, penalty, paidPenalty, unpaidPenalty) => [String(n), date, what, due, accrued, '0', paid, returned, debt, over, '0', '', paidPenalty, unpaidPenalty];

const social = {
  headers: HEAD,
  rows: [
    row(1, '_', 'Сальдо на', '01.01.2026', '0', '0', '0', '0', '0', '0', '0', '0'),
    row(20, '16.04.2026', 'Начисление- чакана савдо', '15.10.2026', '440 000', '0', '0', '440 000', '0', '0', '0', '0'),
    row(21, '20.10.2026', 'Сальдо на20.10.2026г.', '20.10.2026', '2 528 000', '2 096 932.41', '3 492.53', '440 000', '0', '0', '5 439.88', '616'),
    row(22, '20.10.2026', 'Нач.часть общ.пени', '20.10.2026', '0', '0', '0', '440 000', '0', '0', '0', '616'),
    row(23, '16.11.2026', 'Нач.часть общ.пени', '16.11.2026', '0', '0', '0', '880 000', '0', '0', '0', '3 942.4'), // forecast
    row(24, '15.12.2026', 'Нач.часть общ.пени', '15.12.2026', '0', '0', '0', '1 320 000', '0', '0', '0', '11 088'), // forecast
  ],
};

test('the standing of a tax is its latest operation up to today, in whole tiyin', () => {
  const s = parseCard(social, '2026-10-24');

  assert.equal(s.as_of, '2026-10-20');
  assert.equal(s.debt_minor, 44_000_000);
  assert.equal(s.penalty_minor, 61_600);
  assert.equal(s.overpaid_minor, 0);
  assert.equal(s.assessed_minor, 252_800_000);
  assert.equal(s.paid_minor, 209_693_241);
});

test('rows dated after today (the cabinet\'s forecast) are not counted', () => {
  const s = parseCard(social, '2026-10-24');
  assert.notEqual(s.debt_minor, 132_000_000);
  assert.equal(parseCard(social, '2026-04-20').as_of, '2026-04-16');
});

test('an overpayment (a prepayment) is read as such, and an empty table is no standing', () => {
  const turnover = { headers: HEAD, rows: [row(1, '_', 'Сальдо на', '01.01.2026', '0', '0', '0', '0', '0', '0', '0', '0'), row(15, '20.10.2026', 'Сальдо на20.10.2026г.', '20.10.2026', '664 000', '1 100 500', '10 170.03', '0', '425 993.97', '0', '336', '0')] };
  const s = parseCard(turnover, '2026-10-24');
  assert.equal(s.debt_minor, 0);
  assert.equal(s.overpaid_minor, 42_599_397);

  assert.equal(parseCard({ headers: HEAD, rows: [] }, '2026-10-24'), null);
});

test('an amount that cannot be read, or a column that is gone, stops the read', () => {
  const bad = { headers: HEAD, rows: [row(1, '20.10.2026', 'Сальдо на', '20.10.2026', '1', '0', '0', 'abc', '0', '0', '0', '0')] };
  assert.throws(() => parseCard(bad, '2026-10-24'), /cannot be read as money/);
  assert.throws(() => parseCard({ headers: ['№', 'Операция санаси'], rows: [] }, '2026-10-24'), /column any more/);
});

test('a tax type is split into its code and name', () => {
  assert.deepEqual(splitTaxType('100 - Айланмадан олинадиган солиқ'), { code: '100', name: 'Айланмадан олинадиган солиқ' });
  assert.equal(splitTaxType('cўм'), null);
});
