import assert from 'node:assert/strict';
import { test } from 'node:test';
import { formFields, parseTurnoverForm, previousMonth, toDraft } from '../src/turnover.js';

// The rows of the form as observed with the period September chosen (the cabinet filled them itself), as non-empty cells.
const main = [
  ['ПИНФЛ', '12345678901234'],
  ['Вид документа', '1', 'Отчетный период', '9', 'месяц', 'Налоговый период', '2026'],
  ['Срок представления Расчета (день/месяц/год)', '15.10.2026'],
  ['Доходы от реализации товаров (услуг) (строка 020+строка 030 приложения № 1)', '010', '66,400,000.00'],
  ['Прочие доходы', '020', '0.00'],
  ['Налогооблагаемый совокупный доход (строка 010 + строка 020)', '030', '66,400,000.00'],
  ['Уменьшение налогооблагаемого совокупного дохода', '040', '0.00'],
  ['Налоговая база – всего (строка 030 - строка 040)', '050', '66,400,000.00'],
  ['Налоговая ставка, в процентах', '060', '1.00'],
  ['Сумма налога с оборота - всего (строка 050 х строка 0601)', '070', '664,000.00'],
  ['Уменьшение суммы налога с оборота', '0701', '0.00'],
  ['Исчисленная сумма налога (строка 070 – строка 0701)', '080', '664,000.00'],
];
const appendix = [
  ['Общие доходы – всего (сумма строк 0101-0105), в том числе:', '010', '66,400,000.00'],
  ['Доходы по электронным счетам-фактурам', '0101', '66,400,000.00'],
  ['Доходы по онлайн контрольно-кассовым машинам', '0102', '0.00'],
  ['Доходы от экспорта', '0103', '0.00'],
  ['Доходы от маркетплейсов', '0104', '0.00'],
  ['Прочие доходы', '0105', '0.00'],
];

test('the cabinet\'s own calculation is read in whole tiyin, with period, rate and due date', () => {
  const parsed = parseTurnoverForm([main, appendix]);

  assert.equal(parsed.period_start, '2026-09-01');
  assert.equal(parsed.period_end, '2026-09-30');
  assert.equal(parsed.due_date, '2026-10-15');
  assert.equal(parsed.rate_bp, 100); // 1.00 percent
  assert.equal(parsed.lines['050'], 6_640_000_000);
  assert.equal(parsed.lines['080'], 66_400_000);
  assert.equal(parsed.lines['0101'], 6_640_000_000);
  assert.deepEqual(formFields(parsed), { tin: '12345678901234', type: 'turnover', period_start: '2026-09-01', period_end: '2026-09-30', base_minor: 6_640_000_000, tax_minor: 66_400_000, payable_minor: 66_400_000 });
  assert.equal(toDraft(parsed, '10190_14').form, '10190_14');
});

test('a line the cabinet has not filled in stops the read instead of becoming zero', () => {
  const noTax = main.filter((r) => r[1] !== '070');
  assert.throws(() => parseTurnoverForm([noTax, appendix]), /line\(s\) 070/);
  assert.throws(() => parseTurnoverForm([main, appendix.slice(0, 3)]), /0103/);
});

test('an amount that is not money, or a form without a period, is not read', () => {
  const bad = main.map((r) => (r[1] === '050' ? [r[0], '050', 'n/a'] : r));
  assert.throws(() => parseTurnoverForm([bad, appendix]), /cannot be read as money/);

  const noPeriod = main.map((r) => (r[0] === 'Вид документа' ? ['Вид документа', '1'] : r));
  assert.throws(() => parseTurnoverForm([noPeriod, appendix]), /which period/);
});

test('the month before a date', () => {
  assert.deepEqual(previousMonth(new Date(2026, 10, 1)), { year: 2026, month: 10 });
  assert.deepEqual(previousMonth(new Date(2026, 0, 15)), { year: 2025, month: 12 });
});
