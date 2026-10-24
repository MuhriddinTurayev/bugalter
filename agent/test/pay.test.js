import assert from 'node:assert/strict';
import { test } from 'node:test';
import { amountFromField, amountText, modalMatches, nextOrderNumber, pickAccount, pickCode, pickOption, plain } from '../src/pay.js';

test('names compare equal in Cyrillic and Latin', () => {
  assert.equal(plain('Учтепа тумани'), plain('Uchtepa tumani'));
  assert.equal(plain('Тошкент шаҳар'), plain("Toshkent shahar"));
  assert.notEqual(plain('Чилонзор'), plain('Учтепа'));
  assert.equal(pickOption(['Чилонзор тумани', 'Учтепа тумани'], 'Uchtepa'), 1);
  assert.equal(pickOption(['Чилонзор тумани'], 'Учтепа'), null);
  assert.equal(pickOption(['x'], ''), null);
});

test('tax and payment type are chosen by their code, never by a loose match', () => {
  const taxes = ['38 - ЯТМФ билан шуғулланувчи Жис-й шахс. Суғурта бадали', '47 - Қатъий белгиланган миқдордаги солиқ', '100 - Айланмадан олинадиган солиқ', '199 - СОРЖ-199'];
  assert.equal(pickCode(taxes, '100'), 2);
  assert.equal(pickCode(taxes, '10'), null); // "10" is not "100"
  assert.equal(pickCode(['08102-Ҳисоботга асосан ...', '08101-Солиқлар ... олдиндан тўлов'], '08101'), 1);
  assert.equal(pickCode([], '100'), null);
});

test('the payer account is the one that contains the company account, or the only one offered', () => {
  assert.equal(pickAccount(['20218000907449368001', '20218000000000000002'], '20218000000000000002'), 1);
  assert.equal(pickAccount(['20218000907449368001'], ''), 0);
  assert.equal(pickAccount(['a1', 'b2'], ''), null); // two accounts and no choice: a person decides
  assert.equal(pickAccount(['2021 8000 9074 4936 8001'], '20218000907449368001'), 0);
});

test('the amount for the cabinet field and back, in whole tiyin', () => {
  assert.equal(amountText(100_000_000), '1000000');
  assert.equal(amountText(100_050), '1000.50');
  assert.equal(amountText(5), '0.05');
  assert.equal(amountFromField('1 000 000'), 100_000_000);
  assert.equal(amountFromField('1000000.5'), 100_000_050);
  assert.equal(amountFromField('1,5'), 150);
  assert.equal(amountFromField('abc'), null);
  assert.equal(amountFromField(''), null);
});

test('the next order number is one more than the biggest of this year', () => {
  const p = [{ number: '18', date: '2026-10-24' }, { number: '12', date: '2026-08-12' }, { number: '99', date: '2025-12-30' }];
  assert.equal(nextOrderNumber(p, 2026), '19');
  assert.equal(nextOrderNumber([], 2026), '1');
});

test('the confirmation window must show the approved amount, tax and payment type', () => {
  const text = 'Пожалуйста, проверьте правильность введенных данных Вид налога 100-Налог с оборота № / дата 0018 24.10.2026 Сумма 1 000 000 один миллион сум 0 тийин Вид платежа 08101';
  assert.equal(modalMatches(text, { amountMinor: 100_000_000, taxCode: '100', kind: '08101' }), true);
  assert.equal(modalMatches(text, { amountMinor: 100_000_000, taxCode: '100', kind: '08102' }), false);
  assert.equal(modalMatches(text, { amountMinor: 200_000_000, taxCode: '100', kind: '08101' }), false);
  assert.equal(modalMatches(text, { amountMinor: 100_000_000, taxCode: '47', kind: '08101' }), false);
});
