import assert from 'node:assert/strict';
import { test } from 'node:test';
import { FORBIDDEN, followable, pageKey } from '../src/discover.js';

test('only the cabinet\'s own plain pages are followed', () => {
  assert.ok(followable('/with-sidebar/services', 'Барча хизматлар'));
  assert.equal(followable('https://evil.example/x', 'x'), null);
  assert.equal(followable('https://my.soliq.uz.evil.example/x', 'x'), null);
  assert.equal(followable('#top', 'x'), null);
  assert.equal(followable('javascript:alert(1)', 'x'), null);
  assert.equal(followable('/login?type=legal', 'Кириш'), null);
});

test('sign-out, payment, signing, sending and deleting controls are never followed', () => {
  for (const text of ['Чиқиш', 'Выход', 'Logout', 'Тўлов', 'Оплатить', 'Имзолаш', 'Юбориш', 'Отправить', 'Ўчириш', 'Удалить', 'Тасдиқлаш'])
    assert.ok(FORBIDDEN.test(text), text);
  assert.equal(followable('/pay/123', 'x'), null);
  assert.equal(followable('/x/logout', 'Menu'), null);
  assert.equal(FORBIDDEN.test('Ҳисоботлар'), false);
});

test('pages of one kind share a key', () => {
  assert.equal(pageKey(new URL('https://my.soliq.uz/report/12345678/view')), '/report/#/view');
  assert.equal(pageKey(new URL('https://my.soliq.uz/a?x=1')), '/a');
});

test('the reporting system host is followed, look-alike hosts are not', () => {
  assert.ok(followable('https://oldmy.soliq.uz/main/cabinet/redirect-to?redirectUrl=/wefo4-clientui/', 'Ҳисоботлар'));
  assert.equal(followable('https://oldmy.soliq.uz.evil.example/x', 'x'), null);
  assert.equal(followable('https://evil-oldmy.soliq.uz.example/x', 'x'), null);
  assert.equal(followable('https://soliq.uz.evil.example/', 'x'), null);
  assert.equal(pageKey(new URL('https://oldmy.soliq.uz/wefo4-clientui/#/reports/123456')), 'https://oldmy.soliq.uz/wefo4-clientui/#/reports/#');
});
