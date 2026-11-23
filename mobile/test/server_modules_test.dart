import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:bugalter_mobile/data/cabinet.dart';
import 'package:bugalter_mobile/data/extras.dart';
import 'package:bugalter_mobile/data/legal.dart';
import 'package:bugalter_mobile/data/models.dart';
import 'package:bugalter_mobile/data/reports.dart';
import 'package:bugalter_mobile/data/store.dart';

/// What the server answers to the module endpoints, as realistic JSON: nulls, empty PHP arrays (`[]` instead of `{}`), codes the app has no
/// word for, a status the app does not know, amounts in tiyin.
Map<String, dynamic> _server() => jsonDecode(jsonEncode({
      'cabinet': {
        'overview': {
          'taxes': [
            {
              'code': '100', 'name': 'Aylanmadan olinadigan soliq', 'named': false, 'known': true, 'as_of': '2026-11-20', 'debt_minor': 1500000000, 'penalty_minor': 20000000,
              'overpaid_minor': 0, 'paid_since_minor': 500000000, 'remaining_minor': 1020000000, 'overpaid_now_minor': 0, 'card_paid_minor': 9000000000,
              'card_returned_minor': 0, 'paid_year_minor': 9500000000, 'paid_year_orders': 4,
            },
            {
              'code': '38', 'name': null, 'named': false, 'known': true, 'as_of': null, 'debt_minor': 0, 'penalty_minor': 0, 'overpaid_minor': 10000000, 'paid_since_minor': 0,
              'remaining_minor': 0, 'overpaid_now_minor': 30000000, 'card_paid_minor': 0, 'card_returned_minor': 0, 'paid_year_minor': 0, 'paid_year_orders': 0,
            },
            // a tax the cabinet did not list: nothing known, nothing shown
            {
              'code': '199', 'name': null, 'named': false, 'known': false, 'as_of': null, 'debt_minor': 0, 'penalty_minor': 0, 'overpaid_minor': 0, 'paid_since_minor': 0,
              'remaining_minor': 0, 'overpaid_now_minor': 0, 'card_paid_minor': 0, 'card_returned_minor': 0, 'paid_year_minor': 0, 'paid_year_orders': 0,
            },
            // a tax the app has no word for
            {
              'code': 'property', 'name': 'Mol-mulk solig‘i', 'named': true, 'known': true, 'as_of': '2026-11-20', 'debt_minor': 5, 'penalty_minor': 0, 'overpaid_minor': 0,
              'paid_since_minor': 0, 'remaining_minor': 5, 'overpaid_now_minor': 0, 'card_paid_minor': 0, 'card_returned_minor': 0, 'paid_year_minor': 0, 'paid_year_orders': 0,
            },
          ],
          'totals': {'debt_minor': 1500000005, 'penalty_minor': 20000000, 'overpaid_minor': 30000000, 'remaining_minor': 1020000005, 'paid_year_minor': 9500000000, 'paid_year_orders': 4},
          'letters': [
            {'date': '2026-11-10', 'title': 'Talabnoma №12345 (javob: 2026-12-01)', 'kind': 'requirement', 'number': '12345', 'deadline': '2026-12-01', 'unread': true},
            {'date': '2026-11-02', 'title': 'Xabarnoma: hisobot topshirish muddati', 'kind': null, 'number': null, 'deadline': null, 'unread': false},
          ],
          'reports': [
            {'type': 'turnover', 'form': '', 'name': 'Aylanma', 'period_start': '2026-07-01', 'period_end': '2026-09-30', 'status': 'accepted', 'submitted_at': '2026-10-10', 'receipt': 'R-1', 'export_b64': null},
            {'type': null, 'form': '', 'name': '', 'period_start': null, 'period_end': null, 'status': 'submitted', 'submitted_at': null, 'receipt': null, 'export_b64': null},
            {'type': 'payroll', 'form': '', 'name': '', 'period_start': '2026-10-01', 'period_end': null, 'status': 'rejected', 'submitted_at': null, 'receipt': null, 'export_b64': null},
          ],
        },
        'readAt': '2026-11-23T08:00:00',
        'lastCheck': {
          'id': 7, 'status': 'completed', 'actor': null, 'startedAt': '2026-11-23T07:59:00', 'finishedAt': null, 'error': null,
          'summary': {'submitted': 3, 'missing': 1, 'overdue': 1, 'rejected': 0},
          'findings': [
            {'key': 'debt', 'severity': 'warning', 'params': {'tax': '100 - Aylanma', 'amount_minor': 1500000000}},
            {'key': 'overdue', 'severity': 'error', 'type': 'turnover', 'period_kind': 'quarter', 'period_start': '2026-07-01', 'due_date': '2026-10-20', 'report_id': null, 'params': {'days': 34}},
            {'key': 'period_mismatch', 'severity': 'warning', 'type': 'payroll', 'period_kind': 'quarter', 'period_start': '2026-07-01', 'due_date': null, 'report_id': null, 'params': {'cabinet': 'month'}},
            {'key': 'tin_mismatch', 'severity': 'error', 'params': {'portal': '123', 'company': '456'}},
            {'key': 'something_new', 'severity': 'bogus', 'params': []},
          ],
        },
        'orders': [
          {'id': 1, 'number': '202611', 'date': '2026-11-05', 'taxCode': '100', 'tax': 'turnover', 'amountMinor': 500000000, 'statusText': 'Bajarildi', 'paid': true, 'year': '2026'},
          {'id': 2, 'number': null, 'date': null, 'taxCode': null, 'tax': null, 'amountMinor': 100, 'statusText': null, 'paid': false, 'year': '2026'},
          {'id': 3, 'number': '3', 'date': null, 'taxCode': '777', 'tax': 'other', 'amountMinor': 1, 'statusText': '', 'paid': false, 'year': '2025'},
        ],
        'payments': [
          {'id': 11, 'taxCode': '100', 'kind': '08102', 'amountMinor': 500000000, 'purpose': null, 'dryRun': false, 'status': 'queued', 'approvedBy': 'Karim', 'approvedAt': '2026-11-22T10:00:00', 'orderNumber': null, 'orderDate': null, 'error': null, 'createdAt': '2026-11-22T09:55:00'},
          {'id': 12, 'taxCode': '38', 'kind': '08101', 'amountMinor': 100, 'purpose': 'x', 'dryRun': true, 'status': 'failed', 'approvedBy': null, 'approvedAt': null, 'orderNumber': null, 'orderDate': null, 'error': 'Bank rad etdi', 'createdAt': null},
          {'id': 13, 'taxCode': '100', 'kind': '08102', 'amountMinor': 7, 'purpose': null, 'dryRun': false, 'status': 'paid', 'approvedBy': 'Karim', 'approvedAt': '2026-11-01T10:00:00', 'orderNumber': '555', 'orderDate': '2026-11-01', 'error': null, 'createdAt': '2026-11-01T09:00:00'},
          {'id': 14, 'taxCode': null, 'kind': null, 'amountMinor': 0, 'purpose': null, 'dryRun': false, 'status': 'a-status-from-the-future', 'approvedBy': null, 'approvedAt': null, 'orderNumber': null, 'orderDate': null, 'error': null, 'createdAt': null},
        ],
      },
      'letters': {
        'letters': [
          {
            'id': 5, 'kind': 'requirement', 'number': '12345', 'date': '2026-11-10', 'deadline': '2026-12-01', 'answered': false, 'titleUz': 'Talabnoma №12345', 'titleRu': 'Требование №12345',
            'body': 'Matn ' * 4000, 'hasPdf': true, 'firstSeenAt': '2026-11-10T08:00:00',
            'translations': {'tg': {'title': 'Талабнома №12345', 'body': 'Матн', 'summary': 'Хулоса'}},
          },
          {'id': 6, 'kind': 'notice', 'number': null, 'date': null, 'deadline': null, 'answered': true, 'titleUz': '', 'titleRu': 'Только русский', 'body': '', 'hasPdf': false, 'translations': [], 'firstSeenAt': null},
        ],
      },
      'didox': {
        'documents': [
          {
            'id': 21, 'direction': 'in', 'kind': 'Шартнома', 'title': null, 'number': '7', 'date': '2026-11-01', 'counterparty': 'OOO Test', 'counterpartyTin': '301234567', 'contract': null,
            'amountMinor': 1200000000, 'status': 'waiting_me', 'statusText': 'Imzongizni kutmoqda', 'signState': null, 'signAction': null, 'signReason': null, 'signError': null,
            'signApprovedAt': null, 'signatures': [{'name': 'Ali', 'signed_at': '2026-11-02 10:00:00', 'serial': 'A1', 'company': 'OOO Test', 'id_number': '', 'status': 'ok', 'operator': ''}],
            'related': ['AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'], 'body': 'Договор поставки между сторонами. ' * 200, 'translations': {'tg': {'body': 'Шартнома', 'summary': 'Хулосаи шартнома'}}, 'canMakeInvoice': false,
          },
          {
            'id': 22, 'direction': 'out', 'kind': 'Ҳисоб-фактура', 'title': 'Faktura', 'number': null, 'date': null, 'counterparty': null, 'counterpartyTin': null, 'contract': '7', 'amountMinor': 0,
            'status': 'status-of-the-future', 'statusText': 'Yangi holat', 'signState': 'queued', 'signAction': 'sign', 'signReason': null, 'signError': 'xato', 'signApprovedAt': '2026-11-22T10:00:00',
            'signatures': [], 'related': [], 'body': '', 'translations': [], 'canMakeInvoice': false,
          },
        ],
      },
      'legal': {
        'drafts': [
          {
            'id': 31, 'kind': 'contract', 'title': 'Xizmat shartnomasi', 'counterparty': 'OOO Test', 'counterpartyTin': '301234567', 'amountMinor': 500000000, 'language': 'uz', 'body': 'Shartnoma matni __________',
            'missing': ['Manzil', 'Bank'], 'filled': [{'fact': 'Direktor', 'value': 'Karimov'}], 'status': 'draft', 'error': null, 'didoxState': null, 'didoxId': null, 'didoxAt': null, 'approvedBy': null,
            'approvedAt': null, 'actor': 'AI', 'createdAt': '2026-11-20T10:00:00',
          },
          {
            'id': 32, 'kind': 'a-new-kind', 'title': null, 'counterparty': null, 'counterpartyTin': null, 'amountMinor': 0, 'language': 'xx', 'body': '', 'missing': [], 'filled': [], 'status': 'writing',
            'error': null, 'didoxState': 'sent', 'didoxId': 'ABC', 'didoxAt': '2026-11-21T10:00:00', 'approvedBy': 'Karim', 'approvedAt': '2026-11-21T09:00:00', 'actor': null, 'createdAt': '2026-11-21T08:00:00',
          },
        ],
      },
      'advances': {
        'advances': [
          {'id': 41, 'employeeId': 3, 'employee': 'Karimov Ali', 'period': '2026-11-01', 'givenOn': '2026-11-10', 'amountMinor': 200000000, 'note': '', 'transactionId': 99},
          {'id': 42, 'employeeId': 4, 'employee': null, 'period': '2026-11-01', 'givenOn': '2026-11-12', 'amountMinor': 100000000, 'note': 'x', 'transactionId': null},
          {'id': 43, 'employeeId': 3, 'employee': 'Karimov Ali', 'period': null, 'givenOn': null, 'amountMinor': 1, 'note': '', 'transactionId': null},
        ],
      },
      'kpi': {
        'bonuses': [
          {'id': 51, 'employeeId': 3, 'employee': 'Karimov Ali', 'period': '2026-11-01', 'title': 'Savdo rejasi', 'maxBonusMinor': 300000000, 'achievementBp': 8500, 'amountMinor': 255000000, 'note': ''},
        ],
      },
      'signing-keys': {
        'keys': [
          {'id': 61, 'label': 'ERI direktor', 'owner': 'Karimov', 'ownerTin': '301234567', 'serial': 'AB12', 'validFrom': '2026-01-01', 'validTo': '2027-01-01', 'status': 'active', 'usable': true, 'daysLeft': 39, 'revokedAt': null},
          {'id': 62, 'label': null, 'owner': null, 'ownerTin': null, 'serial': null, 'validFrom': null, 'validTo': '2026-12-01', 'status': 'revoked', 'usable': false, 'daysLeft': -1, 'revokedAt': '2026-11-01T10:00:00'},
        ],
      },
    })) as Map<String, dynamic>;

Future<AppStore> _serverStore(Map<String, dynamic>? server, {bool keepDemo = true}) async {
  SharedPreferences.setMockInitialValues({});
  final s = await AppStore.open();
  if (!keepDemo) s.ext.clear();
  s.serverMode = true;
  if (server != null) s.ext['server'] = server;
  return s;
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  group('tax cabinet in server mode', () {
    test('shows the cabinet the robot read: real numbers, no demo data, nothing seeded', () async {
      final s = await _serverStore(_server(), keepDemo: false);
      final d = CabinetData.of(s);
      expect(d.live, isTrue);
      expect(d.synced && d.canSeeCabinet && d.canSeeLetters, isTrue);
      expect(s.ext.containsKey('cabinet'), isFalse, reason: 'server mode never seeds or stores the demo cabinet');

      // 100, 38 and the unlisted-by-app tax; the tax the cabinet did not list (199) is not shown
      expect(d.balances, hasLength(3));
      final turnover = d.balances.firstWhere((b) => b.tax == '100');
      expect(turnover.debt, 1020000000); // the web's headline: debt + penalty - paid since the card
      expect(turnover.penalty, 20000000);
      expect(turnover.paidYear, 9500000000);
      expect(turnover.asOf, DateTime(2026, 11, 20));
      expect(turnover.live, isTrue);
      expect(d.balances.firstWhere((b) => b.tax == '38').overpaid, 30000000);
      final other = d.balances.firstWhere((b) => b.tax == 'other');
      expect(other.name, 'Mol-mulk solig‘i');
      expect(other.debt, 5);

      expect(d.totalDebt, 1020000005);
      expect(d.totalPenalty, 20000000);
      expect(d.totalOverpaid, 30000000);
      expect(d.paidThisYear(), 9500000000);
      expect(d.readAt, DateTime(2026, 11, 23, 8));
    });

    test('reports, orders and payments survive nulls and unknown values', () async {
      final d = CabinetData.of(await _serverStore(_server()));
      // a report without a period is not a row; one without an end day takes its start
      expect(d.reports.map((r) => r.type), ['turnover', 'payroll']);
      expect(d.reports.last.end, d.reports.last.start);
      expect(d.reports.last.status, 'rejected');

      expect(d.orders, hasLength(3)); // an order with no date takes the 1st of its year
      expect(d.orders.map((o) => o.amount), containsAll([500000000, 100, 1]));
      expect(d.orders.firstWhere((o) => o.number == '202611').statusText, 'Bajarildi');
      expect(d.orders.firstWhere((o) => o.amount == 1).tax, 'other');

      final pays = {for (final p in d.payments) p.id: p};
      expect(pays[11]!.status, PayStatus.queued);
      expect(pays[11]!.approvedBy, 'Karim');
      expect(pays[12]!.status, PayStatus.failed);
      expect(pays[12]!.dryRun, isTrue);
      expect(pays[12]!.error, 'Bank rad etdi');
      expect(pays[13]!.status, PayStatus.paid);
      expect(pays[13]!.orderNumber, '555');
      expect(pays[14]!.status, PayStatus.proposed, reason: 'an unknown status is shown as not done, never as paid');
      expect(pays[14]!.amount, 0);
    });

    test('the last read of the cabinet keeps its findings; unknown severities and keys do not break it', () async {
      final d = CabinetData.of(await _serverStore(_server()));
      final c = d.lastCheck!;
      expect(c.at, DateTime(2026, 11, 23, 7, 59));
      expect(c.by, '');
      expect((c.submitted, c.missing, c.overdue, c.rejected), (3, 1, 1, 0));
      expect(c.findings, hasLength(5));
      final debt = c.findings.firstWhere((f) => f.key == 'debt');
      expect(debt.amount, 1500000000);
      expect(debt.tax, '100 - Aylanma');
      final overdue = c.findings.firstWhere((f) => f.key == 'overdue');
      expect(overdue.start, DateTime(2026, 7, 1));
      expect(overdue.end, DateTime(2026, 9, 30)); // a quarter from its first day
      expect(overdue.due, DateTime(2026, 10, 20));
      expect(overdue.days, 34);
      expect(c.findings.firstWhere((f) => f.key == 'period_mismatch').portalValue, 'month');
      expect(c.findings.firstWhere((f) => f.key == 'tin_mismatch').tax, '456');
      expect(c.findings.firstWhere((f) => f.key == 'something_new').severity, Severity.info);
    });

    test('letters: titles in each language, translations, unread from the inbox, titles-only messages', () async {
      final d = CabinetData.of(await _serverStore(_server()));
      final byId = {for (final l in d.letters) l.id: l};
      final l = byId[5]!;
      expect(l.isDemand, isTrue);
      expect(l.unread, isTrue); // from the cabinet's inbox, matched by number
      expect(l.titleIn('uz'), 'Talabnoma №12345');
      expect(l.titleIn('ru'), 'Требование №12345');
      expect(l.titleIn('tg'), 'Талабнома №12345');
      expect(l.isTranslation('tg'), isTrue);
      expect(l.isTranslation('uz'), isFalse);
      expect(l.bodyIn('tg'), 'Матн');
      expect(l.bodyIn('ru'), startsWith('Matn Matn'));
      expect(l.body['orig'], l.bodyIn('ru'));
      expect(l.summaryIn('tg'), 'Хулоса');
      expect(l.summaryIn('uz'), isNull);
      expect(l.date, DateTime(2026, 11, 10));
      expect(l.deadline, DateTime(2026, 12, 1));

      // a letter without a Uzbek title and without text, answered
      final n = byId[6]!;
      expect(n.titleIn('uz'), 'Только русский');
      expect(n.bodyIn('uz'), '');
      expect(n.answered, isTrue);
      expect(n.number, '');

      // the inbox has two messages; the first matches letter 5, the second only has a title
      final synthetic = d.letters.where((x) => x.id < 0).toList();
      expect(synthetic, hasLength(1));
      expect(synthetic.single.titleIn('uz'), startsWith('Xabarnoma'));
      expect(synthetic.single.bodyIn('uz'), '');
      expect(d.unreadLetters, 1);
      expect(d.unansweredDemands, 1);
    });

    test('every change is refused (read-only) and nothing is written', () async {
      final s = await _serverStore(_server());
      final demoBefore = jsonEncode(s.ext['cabinet']);
      final d = CabinetData.of(s);
      void refused(void Function() f) => expect(f, throwsA(isA<AppError>().having((e) => e.key, 'key', 'server_readonly')));
      refused(() => d.checkNow());
      refused(() => d.propose(tax: 'vat', kind: '08102', amount: 100));
      refused(() => d.checkPayment(d.payments.first));
      refused(() => d.pay(d.payments.first, tax: '100', kind: '08102', amount: 1));
      refused(() => d.cancelPayment(d.payments.first));
      refused(() => d.settleUnknown(d.payments.first));
      refused(() => d.payAll(''));
      refused(() => d.startRun(DateTime(2026, 10, 1)));
      d.markRead(d.letters.firstWhere((l) => l.unread)); // opening a letter never changes what the cabinet says
      expect(d.letters.where((l) => l.unread), isNotEmpty);
      expect(jsonEncode(s.ext['cabinet']), demoBefore, reason: 'the demo cabinet is not touched in server mode');
    });

    test('no access and not read yet are told apart from "nothing to show"', () async {
      final none = CabinetData.of(await _serverStore(<String, dynamic>{})); // the server answered 403 to everything
      expect(none.synced, isTrue);
      expect(none.canSeeCabinet, isFalse);
      expect(none.canSeeLetters, isFalse);
      expect(none.balances, isEmpty);
      expect(none.letters, isEmpty);
      expect(none.lastCheck, isNull);
      expect(none.totalDebt, 0);

      final notYet = CabinetData.of(await _serverStore(null, keepDemo: false)); // not read at all yet
      expect(notYet.synced, isFalse);
      expect(notYet.balances, isEmpty);
      expect(notYet.paidThisYear(), 0);

      // a cabinet the robot has never read: everything empty, no readAt
      final empty = CabinetData.of(await _serverStore({
        'cabinet': {'overview': {'taxes': [], 'totals': {}, 'letters': [], 'reports': []}, 'readAt': null, 'lastCheck': null, 'orders': [], 'payments': []},
        'letters': {'letters': []},
      }));
      expect(empty.canSeeCabinet, isTrue);
      expect(empty.readAt, isNull);
      expect(empty.balances, isEmpty);
      expect(empty.totalDebt, 0);
      expect(empty.paidThisYear(), 0);
    });

    test('read again when the phone reads the server again', () async {
      final s = await _serverStore(_server());
      expect(CabinetData.of(s).balances, hasLength(3));
      expect(identical(CabinetData.of(s), CabinetData.of(s)), isTrue, reason: 'cached while the raw answers are the same');
      final fresh = _server();
      ((fresh['cabinet'] as Map)['overview'] as Map)['taxes'] = [];
      s.ext['server'] = fresh;
      expect(CabinetData.of(s).balances, isEmpty);
    });
  });

  group('Didox and the lawyer in server mode', () {
    test('documents: kinds from Didox\'s words, nulls, signatures, translations, nothing signable', () async {
      final s = await _serverStore(_server(), keepDemo: false);
      final data = LegalData.of(s);
      expect(data.live, isTrue);
      expect(data.synced && data.canSeeDidox && data.canSeeLegal, isTrue);
      expect(s.ext.containsKey('legal'), isFalse);

      expect(data.docs, hasLength(2));
      final a = data.doc(21)!;
      expect(a.kind, 'contract');
      expect(a.incoming, isTrue);
      expect(a.status, 'waiting_me');
      expect(a.waitsForUs, isTrue);
      expect(a.canSign, isFalse, reason: 'the phone never signs a real document');
      expect(a.canReject || a.canCancel || a.canMakeInvoice, isFalse);
      expect(a.amount, 1200000000);
      expect(a.dateOrNull, DateTime(2026, 11, 1));
      expect(a.signatures.single.name, 'Ali');
      expect(a.signatures.single.at, DateTime(2026, 11, 2, 10));
      expect(a.textIn('tg'), 'Шартнома');
      expect(a.summaries['tg'], 'Хулосаи шартнома');
      expect(a.bodyLang, 'ru'); // guessed from the letters of the text
      expect(a.body.length, greaterThan(5000));
      expect(a.related, isEmpty);

      final b = data.doc(22)!;
      expect(b.kind, 'invoice');
      expect(b.amount, isNull);
      expect(b.dateOrNull, isNull);
      expect(b.counterparty, '');
      expect(b.status, 'status-of-the-future');
      expect(b.statusText, 'Yangi holat');
      expect(b.signState, 'queued');
      expect(b.signError, 'xato');
      expect(b.translations, isEmpty);
      expect(data.sorted(), hasLength(2));
      expect(data.toSign, isEmpty);
      expect(data.countStatus('open'), 1);
    });

    test('drafts: statuses, kinds the app has no template for, empty arrays', () async {
      final data = LegalData.of(await _serverStore(_server()));
      expect(data.drafts, hasLength(2));
      final a = data.draft(31)!;
      expect(a.live, isTrue);
      expect(a.status, 'draft');
      expect(a.templateId, 'contract');
      expect(a.party, 'OOO Test');
      expect(a.answers['amount'], '500000000');
      expect(a.missing, ['Manzil', 'Bank']);
      expect(a.filled.single, {'fact': 'Direktor', 'value': 'Karimov'});
      expect(a.actorName, 'AI');
      expect(a.createdAt, DateTime(2026, 11, 20, 10));
      expect(a.locked, isTrue, reason: 'a real draft is never edited on the phone');
      expect(a.template, isNotNull); // an unknown kind does not throw

      final b = data.draft(32)!;
      expect(b.templateId, 'a-new-kind');
      expect(b.status, 'sent'); // the server's `writing` is overridden by "sent to Didox"
      expect(b.language, 'uz');
      expect(b.title, '');
      expect(b.approvedBy, 'Karim');
      expect(b.didoxState, 'sent');
      expect(b.template, isNotNull);
      expect(data.draftsSorted().first.id, 32);
    });

    test('a draft that is still being written, and every change refused', () async {
      final raw = _server();
      ((raw['legal'] as Map)['drafts'] as List)[1]['didoxState'] = null;
      final s = await _serverStore(raw);
      final data = LegalData.of(s);
      expect(data.draft(32)!.status, 'writing');
      void refused(void Function() f) => expect(f, throwsA(isA<AppError>().having((e) => e.key, 'key', 'server_readonly')));
      final doc = data.doc(21)!;
      final draft = data.draft(31)!;
      refused(() => data.signDoc(s, doc));
      refused(() => data.signAll(s));
      refused(() => data.reject(s, doc, 'x'));
      refused(() => data.cancel(s, doc));
      refused(() => data.createDraft(s, 'services', 'uz'));
      refused(() => data.saveAnswers(s, draft, {'party': 'x'}));
      refused(() => data.fill(s, draft));
      refused(() => data.saveText(s, draft, title: 'a', body: 'b'));
      refused(() => data.approve(s, draft));
      refused(() => data.sendToDidox(s, draft, number: '1', date: DateTime(2026, 11, 23)));
      refused(() => data.deleteDraft(s, draft));
      expect(data.drafts, hasLength(2));
      expect(data.docs, hasLength(2));
    });

    test('no access, not read yet, and the demo documents are never shown', () async {
      final none = LegalData.of(await _serverStore(<String, dynamic>{}));
      expect(none.synced, isTrue);
      expect(none.canSeeDidox, isFalse);
      expect(none.canSeeLegal, isFalse);
      expect(none.docs, isEmpty);
      expect(none.drafts, isEmpty);

      final s = await _serverStore(null); // demo books with their demo documents are still in `ext`
      final notYet = LegalData.of(s);
      expect(notYet.synced, isFalse);
      expect(notYet.docs, isEmpty);
      expect(notYet.drafts, isEmpty);

      s.serverMode = false; // back to demo: the demo documents are there again
      expect(LegalData.of(s).live, isFalse);
      expect(LegalData.of(s).docs, isNotEmpty);
    });
  });

  group('advances, KPI and ERI keys in server mode', () {
    test('real advances and bonuses, in whole tiyin, with the names the server kept', () async {
      final s = await _serverStore(_server(), keepDemo: false);
      final nov = DateTime(2026, 11, 1);
      expect(s.extrasSynced() && s.extrasAllowed('advances') && s.extrasAllowed('kpi') && s.extrasAllowed('keys'), isTrue);
      expect(s.ext.containsKey('extras'), isFalse, reason: 'nothing is seeded in server mode');

      // the advance with no dates is not a row
      final list = s.advancesOf(nov);
      expect(list.map((a) => a.id), [42, 41]); // newest first
      expect(list.fold(0, (a, x) => a + x.amountMinor), 300000000);
      expect(list.firstWhere((a) => a.id == 41).employeeName, 'Karimov Ali');
      expect(list.firstWhere((a) => a.id == 42).employeeName, '');
      expect(s.advanceTotals(nov), {3: 200000000, 4: 100000000});

      final kpi = s.kpiOf(nov).single;
      expect(kpi.title, 'Savdo rejasi');
      expect(kpi.amountMinor, 255000000); // 3 000 000 so'm x 85 %
      expect(s.kpiTotals(nov), {3: 255000000});
      expect(s.advancesOf(DateTime(2026, 10, 1)), isEmpty);
    });

    test('ERI keys: dates, revoked, no owner details', () async {
      final s = await _serverStore(_server());
      final keys = s.eriKeys;
      expect(keys, hasLength(2));
      final a = keys.firstWhere((k) => k.id == 61);
      expect(a.serial, 'AB12');
      expect(a.validTo, DateTime(2027, 1, 1));
      expect(a.state(DateTime(2026, 11, 23)), EriState.valid);
      final b = keys.firstWhere((k) => k.id == 62);
      expect(b.revoked, isTrue);
      expect(b.label, '');
      expect(b.ownerName, '');
      expect(b.state(DateTime(2026, 11, 23)), EriState.revoked);
    });

    test('every change is refused; an answer the person may not see is told apart', () async {
      final s = await _serverStore(_server());
      final nov = DateTime(2026, 11, 1);
      void refused(void Function() f) => expect(f, throwsA(isA<AppError>().having((e) => e.key, 'key', 'server_readonly')));
      expect(s.canEditPeople, isFalse);
      final e = Employee(id: 3, fullName: 'Karimov Ali', salaryMinor: 500000000);
      refused(() => s.giveAdvance(employee: e, month: nov, givenOn: nov, amountMinor: 100, accountId: 1));
      refused(() => s.updateAdvance(s.advancesOf(nov).first, givenOn: nov, amountMinor: 100, accountId: 1));
      refused(() => s.deleteAdvance(s.advancesOf(nov).first));
      refused(() => s.addKpi(employee: e, month: nov, title: 'x', maxBonusMinor: 100, achievementBp: 100));
      refused(() => s.updateKpi(s.kpiOf(nov).first, title: 'x', maxBonusMinor: 100, achievementBp: 100));
      refused(() => s.deleteKpi(s.kpiOf(nov).first));
      expect(s.advancesOf(nov), hasLength(2));

      final partial = await _serverStore({'advances': {'advances': []}});
      expect(partial.extrasAllowed('advances'), isTrue);
      expect(partial.extrasAllowed('kpi'), isFalse);
      expect(partial.extrasAllowed('keys'), isFalse);
      expect(partial.advancesOf(nov), isEmpty);
      expect(partial.kpiBonuses, isEmpty);
      expect(partial.eriKeys, isEmpty);

      final notYet = await _serverStore(null);
      expect(notYet.extrasSynced(), isFalse);
      expect(notYet.advances, isEmpty);
      expect(notYet.eriKeys, isEmpty, reason: 'the demo ERI keys are never shown for the real company');
    });
  });

  group('financial reports with real data', () {
    Future<AppStore> books(int n, {List<Category> extraCategories = const []}) async {
      final s = await _serverStore(null, keepDemo: false);
      s.txns.clear();
      s.accounts
        ..clear()
        ..add(Account(id: 1, name: 'Hisob', type: AccountType.bank));
      s.categories
        ..clear()
        ..addAll([Category(id: 1, key: 'sales', name: 'Sotuv', type: TxType.income), Category(id: 2, key: 'some_server_only_key', name: 'Maxsus toifa', type: TxType.expense), ...extraCategories]);
      final base = DateTime(2026, 11, 1, 9);
      for (var i = 0; i < n; i++) {
        final income = i % 3 == 0;
        s.txns.add(Txn(
          id: i + 1,
          accountId: i % 50 == 0 ? 999 : 1, // a few belong to an account the phone does not have
          type: income ? TxType.income : TxType.expense,
          amountMinor: 1000 + i,
          occurredAt: base.add(Duration(hours: i % 500)),
          categoryId: i % 7 == 0 ? null : (income ? 1 : 2),
          contractorId: i % 11 == 0 ? 12345 : null, // a contractor that is not on the phone
        ));
      }
      return s;
    }

    test('1500 operations: sums add up, nothing throws, unknown categories and accounts are tolerated', () async {
      final s = await books(1500);
      final r = buildReport(s, DateTime(2026, 11, 1), DateTime(2026, 11, 30), now: DateTime(2026, 11, 23));
      var inc = 0, exp = 0, cnt = 0;
      for (final t in s.txns) {
        if (t.occurredAt.isBefore(DateTime(2026, 12, 1))) {
          cnt++;
          t.type == TxType.income ? inc += t.amountMinor : exp += t.amountMinor;
        }
      }
      expect(r.income, inc);
      expect(r.expense, exp);
      expect(r.net, inc - exp);
      expect(r.count, cnt);
      expect(r.incomeRows.fold(0, (a, c) => a + c.amount), r.income);
      expect(r.expenseRows.fold(0, (a, c) => a + c.amount), r.expense);
      expect(r.incomeRows.any((c) => c.categoryId == null), isTrue); // operations with no category are one row, not an error
      expect(r.buckets, isNotEmpty);
      expect(r.contractors, isEmpty); // the contractor is not on the phone: no row, no crash
      expect(r.flows.single.account.id, 1);
    });

    test('empty periods give zeros and still have chart buckets', () async {
      final s = await books(10);
      final month = buildReport(s, DateTime(2025, 1, 1), DateTime(2025, 1, 31), now: DateTime(2026, 11, 23));
      expect((month.income, month.expense, month.net, month.count), (0, 0, 0, 0));
      expect(month.incomeRows, isEmpty);
      expect(month.flows, isEmpty);
      expect(month.buckets, isNotEmpty);
      final year = buildReport(s, DateTime(2025, 1, 1), DateTime(2025, 12, 31), now: DateTime(2026, 11, 23));
      expect(year.buckets, hasLength(12));
      expect(year.buckets.every((b) => b.monthly && b.income == 0 && b.expense == 0), isTrue);

      final none = await books(0);
      final r = buildReport(none, DateTime(2026, 11, 1), DateTime(2026, 11, 23), now: DateTime(2026, 11, 23));
      expect(r.count, 0);
      expect(r.income + r.expense, 0);
    });
  });
}
