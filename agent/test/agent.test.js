import assert from 'node:assert/strict';
import { test } from 'node:test';
import { datesIn, soum, statusOf, typeOf } from '../src/cabinet.js';
import { normalizeServer } from '../src/config.js';
import { isLoggedIn, matchingCards } from '../src/login.js';
import { formatMinor, parseMinor } from '../src/money.js';

test('amounts are parsed to integer tiyin without floats', () => {
  assert.equal(parseMinor('1 234 567,89'), 123_456_789);
  assert.equal(parseMinor('1,234,567.89'), 123_456_789);
  assert.equal(parseMinor('1234567'), 123_456_700);
  assert.equal(parseMinor('0,07'), 7);
  assert.equal(parseMinor('12,5'), 1250);
  assert.equal(parseMinor('-150 000 so\'m'), -15_000_000);
  // 0.1 + 0.2 style traps: the value is text, never a float.
  assert.equal(parseMinor('0,30'), 30);
});

test('text that is not an amount is null, never a guess', () => {
  for (const bad of ['', 'abc', '1,2,3', '12.345,678', '1 2 3 a', '--5', '99999999999999999999']) assert.equal(parseMinor(bad), null, bad);
  assert.equal(parseMinor(null), null);
});

test('tiyin are formatted back for messages', () => {
  assert.equal(formatMinor(123_456_789), '1 234 567,89');
  assert.equal(formatMinor(-5), '-0,05');
  assert.equal(soum(123_456_789), '1234567,89');
  assert.equal(soum(5), '0,05');
  assert.equal(parseMinor(soum(98_765_432)), 98_765_432);
});

test('only the key card that names the company TIN is selected', () => {
  const cards = ['ООО TEST 300000009 ЭРИ амал қилиш', 'ИП X 200000001', 'Third 300000009'];
  assert.deepEqual(matchingCards(cards, '300000009'), [0, 2]); // two matches: the agent asks the accountant
  assert.deepEqual(matchingCards(cards, '200000001'), [1]);
  assert.deepEqual(matchingCards(cards, '111111111'), []); // none: the agent asks the accountant
});

test('the cabinet counts as open only outside /login on my.soliq.uz', () => {
  assert.equal(isLoggedIn('https://my.soliq.uz/login?type=legal'), false);
  assert.equal(isLoggedIn('https://my.soliq.uz/dashboard'), true);
  assert.equal(isLoggedIn('https://evil.example/dashboard'), false);
  assert.equal(isLoggedIn('https://my.soliq.uz.evil.example/x'), false);
});

test('the server address must be https (http only for localhost) and carry no credentials', () => {
  assert.equal(normalizeServer('https://acme.bugalter.uz/'), 'https://acme.bugalter.uz');
  assert.equal(normalizeServer('http://acme.localhost:8765'), 'http://acme.localhost:8765');
  assert.throws(() => normalizeServer('http://acme.bugalter.uz'));
  assert.throws(() => normalizeServer('https://user:pass@acme.bugalter.uz'));
  assert.throws(() => normalizeServer('ftp://x'));
});

test('cabinet text is read as data: dates, form types and unknown wording', () => {
  assert.deepEqual(datesIn('01.04.2026 - 30.04.2026'), ['2026-04-01', '2026-04-30']);
  assert.deepEqual(datesIn('no dates'), []);
  assert.equal(typeOf('QQS hisoboti (form 1)', { 'qqs': 'vat', 'foyda': 'profit' }), 'vat');
  assert.equal(typeOf('Something else', { 'qqs': 'vat' }), null);
  const words = { accepted: ['қабул қилинди'], rejected: ['рад'] };
  assert.equal(statusOf('Қабул қилинди', words), 'accepted');
  assert.equal(statusOf('Рад этилди', words), 'rejected');
  // Unknown wording is "received", never accepted.
  assert.equal(statusOf('Обрабатывается', words), 'submitted');
});

test('*.localhost is resolved to loopback, real names are left to DNS', async () => {
  const { lookup } = await import('../src/api.js');
  const one = (host, options = {}) => new Promise((resolve) => lookup(host, options, (err, address, family) => resolve([err, address, family])));
  assert.deepEqual(await one('demo1.localhost'), [null, '127.0.0.1', 4]);
  assert.deepEqual(await one('localhost'), [null, '127.0.0.1', 4]);
  const all = await new Promise((resolve) => lookup('acme.localhost', { all: true }, (err, list) => resolve(list)));
  assert.deepEqual(all, [{ address: '127.0.0.1', family: 4 }]);
  assert.notEqual((await one('localhost.evil.example'))[1], '127.0.0.1');
});

test('a cabinet that is not the company\'s stops the task and tells Laravel which identifier it saw', async () => {
  const { assertCompany } = await import('../src/cabinet.js');
  const company = { tin: '300000009', pinfl: null };

  assert.doesNotThrow(() => assertCompany({ tin: '300000009' }, company));
  assert.doesNotThrow(() => assertCompany({ tin: '62510026590062' }, { ...company, pinfl: '62510026590062' }));

  assert.throws(() => assertCompany({ tin: '62510026590062' }, company), (e) => e.seenIdentifier === '62510026590062' && /Wrong taxpayer/.test(e.message) && !e.message.includes('625100'));
  assert.throws(() => assertCompany({ tin: '' }, company), (e) => e.seenIdentifier === undefined);
  assert.throws(() => assertCompany({ tin: '12345' }, company), (e) => e.seenIdentifier === undefined);
  // A company with no identifier yet trusts its first sign-in (the E-IMZO key is the identity) - but never an unreadable one.
  assert.equal(assertCompany({ tin: '62510026590062' }, { tin: '', pinfl: null }), 'first_use');
  assert.equal(assertCompany({ tin: '300000009' }, company), 'known');
  assert.throws(() => assertCompany({ tin: '' }, { tin: '', pinfl: null }), /Wrong taxpayer/);
});

test('waits for the accountant: only "send" goes on, everything else sends nothing', async () => {
  const { waitForDecision } = await import('../src/tasks.js');
  const answers = [{ decision: null }, { decision: null }, { decision: 'send' }];
  await waitForDecision({ decision: async () => answers.shift() }, 1, { everyMs: 0, sleep: async () => {} });
  assert.equal(answers.length, 0);

  await assert.rejects(waitForDecision({ decision: async () => ({ decision: 'maybe' }) }, 1, { sleep: async () => {} }), /not sent/);
  await assert.rejects(waitForDecision({ decision: async () => ({ decision: null }) }, 1, { timeoutMs: 0, sleep: async () => {} }), /Nothing was sent/);
});
