import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:bugalter_mobile/data/legal.dart';
import 'package:bugalter_mobile/data/models.dart';
import 'package:bugalter_mobile/data/store.dart';
import 'package:bugalter_mobile/l10n/strings.dart';

Future<AppStore> _open(String login) async {
  SharedPreferences.setMockInitialValues({});
  final s = await AppStore.open();
  await s.login(login, 'Demo#2026pass');
  return s;
}

Matcher _legalError(String key) => throwsA(isA<LegalError>().having((e) => e.key, 'key', key));

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  group('string keys used dynamically exist in the three languages', () {
    void has(String key) => expect(kAllStrings.containsKey(key), isTrue, reason: 'missing string key: $key');

    test('didox keys', () {
      for (final k in DidoxDoc.kinds) {
        has('didox.kind.$k');
      }
      for (final st in DidoxDoc.statuses) {
        has('didox.status.$st');
        has('didox.ins_status_$st');
      }
      for (final f in ['all', 'open', 'signed', 'rejected', 'deleted']) {
        has('didox.filter.$f');
      }
      for (final d in ['all', 'in', 'out']) {
        has('didox.direction.$d');
      }
    });

    test('legal keys: templates, questions, clauses, statuses, audit', () {
      for (final st in LegalDraft.statuses) {
        has('legal.status.$st');
      }
      for (final t in LegalTemplate.all) {
        has('legal.tpl.${t.id}');
        has('legal.tpl.${t.id}.d');
        has('legal.t.${t.id}.title');
        for (final f in t.fields) {
          has(t.labelKey(f));
        }
        for (final c in t.clauses) {
          has('legal.t.${c.$1}');
          has('legal.t.${c.$2}');
        }
        if (!t.isContract) has('legal.t.${t.id}.body');
        if (t.roles != null) {
          has('legal.t.role.${t.roles!.$1}');
          has('legal.t.role.${t.roles!.$2}');
        }
      }
      for (final r in ['employer', 'employee', 'party1', 'party2']) {
        has('legal.t.role.$r');
      }
      for (final a in ['didox_signed', 'didox_rejected', 'didox_cancelled', 'legal_created', 'legal_filled', 'legal_saved', 'legal_approved', 'legal_sent', 'legal_deleted']) {
        has('audit.$a');
      }
    });
  });

  group('contract texts', () {
    test('every template renders in every language without leftover placeholders and reports what is missing', () async {
      final s = await _open('owner@bugalter.test');
      for (final t in LegalTemplate.all) {
        for (final lang in kLocales) {
          final empty = renderDraft(t: t, lang: lang, company: s.company, answers: {'side': '0'});
          expect(empty.body, isNot(matches(RegExp(r'\{\w+\}'))), reason: '${t.id}/$lang left a placeholder');
          expect(empty.body, contains(kBlank));
          expect(empty.missing.toSet(), t.required, reason: '${t.id}/$lang');

          final full = {
            for (final f in t.fields)
              f: switch (LegalTemplate.kindOf(f)) {
                FieldKind.money => '${25200000 * 100}',
                FieldKind.date => DateTime(2026, 11, 20).toIso8601String(),
                FieldKind.side => '0',
                _ => 'X $f',
              },
          };
          final done = renderDraft(t: t, lang: lang, company: s.company, answers: full);
          expect(done.missing, isEmpty, reason: '${t.id}/$lang');
          expect(done.body, contains('20.11.2026'));
        }
      }
    });

    test('an amount is written from integer tiyin and never invented', () async {
      final s = await _open('owner@bugalter.test');
      final t = LegalTemplate.byId('services');
      final withAmount = renderDraft(t: t, lang: 'uz', company: s.company, answers: {'side': '0', 'amount': '2520000000'});
      expect(withAmount.body, contains('25 200 000'));
      final without = renderDraft(t: t, lang: 'uz', company: s.company, answers: {'side': '0'});
      expect(without.missing, contains('amount'));
    });

    test('"we are the second role" swaps the parties', () async {
      final s = await _open('owner@bugalter.test');
      s.company.name = 'OUR LLC';
      final t = LegalTemplate.byId('services');
      final a = {'party': 'OTHER LLC', 'tin': '111111111'};
      final asExecutor = renderDraft(t: t, lang: 'ru', company: s.company, answers: {...a, 'side': '0'}).body;
      final asCustomer = renderDraft(t: t, lang: 'ru', company: s.company, answers: {...a, 'side': '1'}).body;
      expect(asExecutor.indexOf('OUR LLC'), lessThan(asExecutor.indexOf('OTHER LLC')));
      expect(asCustomer.indexOf('OTHER LLC'), lessThan(asCustomer.indexOf('OUR LLC')));
    });
  });

  group('Didox documents', () {
    test('demo data is seeded once, consistent, and covers every status', () async {
      final s = await _open('owner@bugalter.test');
      final data = LegalData.of(s);
      expect(data.docs, isNotEmpty);
      expect(identical(LegalData.of(s), data), isTrue);
      expect(data.docs.map((d) => d.status).toSet(), containsAll(DidoxDoc.statuses.where((e) => e != 'waiting_agent')));
      for (final d in data.docs) {
        expect(DidoxDoc.statuses, contains(d.status));
        if (d.lines.isNotEmpty) expect(d.amount, d.linesSubtotal + d.linesVat);
      }
      expect(data.toSign, isNotEmpty);
      expect(data.toSign.every((d) => d.incoming && d.status == 'waiting_me'), isTrue);
    });

    test('signing needs a person who may approve and only touches what waits for our signature', () async {
      final cashier = await _open('cashier@bugalter.test');
      final cd = LegalData.of(cashier);
      expect(() => cd.signDoc(cashier, cd.toSign.first), _legalError('didox.errors.no_permission'));
      expect(() => cd.signAll(cashier), _legalError('didox.errors.no_permission'));

      final s = await _open('accountant@bugalter.test');
      final data = LegalData.of(s);
      final waiting = data.toSign.length;
      final one = data.toSign.first;
      data.signDoc(s, one);
      expect(one.status, 'signed');
      expect(one.weSigned && one.signatures.last.sandbox, isTrue);
      expect(() => data.signDoc(s, one), _legalError('didox.errors.not_signable'));
      expect(data.signAll(s), waiting - 1);
      expect(data.toSign, isEmpty);
      expect(() => data.signAll(s), _legalError('didox.errors.nothing_to_sign'));
      expect(s.audit.where((e) => e.action == 'didox_signed'), hasLength(waiting));
    });

    test('reject and cancel follow the web rules', () async {
      final s = await _open('owner@bugalter.test');
      final data = LegalData.of(s);
      final incoming = data.toSign.first;
      data.reject(s, incoming, ' no ');
      expect(incoming.status, 'rejected');
      expect(incoming.rejectReason, 'no');

      final mine = data.docs.firstWhere((d) => d.canCancel);
      data.cancel(s, mine);
      expect(mine.status, 'deleted');
      expect(() => data.cancel(s, mine), _legalError('didox.errors.not_cancellable'));
      final signed = data.docs.firstWhere((d) => d.status == 'signed');
      expect(() => data.reject(s, signed, ''), _legalError('didox.errors.not_signable'));
    });

    test('an invoice or act for the books is prepared from a signed contract, copying the figures', () async {
      final s = await _open('owner@bugalter.test');
      final data = LegalData.of(s);
      final contract = data.docs.firstWhere((d) => d.canMakeInvoice);
      final before = s.contractors.length;
      final (doc, added) = data.prepareBookDoc(s, contract, DocType.invoice);
      expect(doc.type, DocType.invoice);
      expect(doc.status, DocStatus.draft);
      expect(doc.outgoing, isTrue);
      expect(doc.total, contract.amountMinor);
      expect(s.contractor(doc.contractorId), isNotNull);
      expect(s.contractors.length, before + (added ? 1 : 0));

      final unknown = DidoxDoc(
        id: s.nextId(), direction: 'in', kind: 'invoice', docDate: DateTime.now(), counterparty: 'New Supplier LLC', tin: '999888777', status: 'signed',
        lines: [DocLine(name: 'X', qtyMilli: 2000, unitPriceMinor: 50000, vatBp: 1200)],
      );
      final (doc2, added2) = data.prepareBookDoc(s, unknown, DocType.invoice);
      expect(added2, isTrue);
      expect(doc2.outgoing, isFalse);
      expect(doc2.total, unknown.amount);
    });
  });

  group('lawyer drafts', () {
    test('lifecycle: collect, fill, edit, approve, send to Didox', () async {
      final s = await _open('owner@bugalter.test');
      final data = LegalData.of(s);
      final seeded = data.drafts.length;
      expect(data.drafts.map((d) => d.status).toSet(), containsAll(['collecting', 'draft', 'approved']));

      final d = data.createDraft(s, 'services', 'ru');
      expect(d.status, 'collecting');
      expect(data.drafts.length, seeded + 1);

      data.fill(s, d); // nothing answered yet
      expect(d.status, 'failed');
      expect(d.errorKey, 'legal.errors.need_party');

      data.saveAnswers(s, d, {...d.answers, 'party': 'Test LLC', 'tin': '123456789', 'subject': 'Work'}, language: 'ru');
      expect(d.status, 'collecting');
      data.fill(s, d);
      expect(d.status, 'draft');
      expect(d.title, contains('Test LLC'));
      expect(d.missing, containsAll(['amount', 'payment', 'term', 'penalty']));
      expect(d.body, contains('ДОГОВОР'));

      expect(() => data.sendToDidox(s, d, number: '1', date: DateTime.now()), _legalError('legal.errors.not_approved'));
      data.saveText(s, d, title: 'My title', body: '${d.body}\n\nExtra');
      expect(d.body, endsWith('Extra'));
      data.approve(s, d);
      expect(d.status, 'approved');
      expect(d.approvedBy, isNotEmpty);
      expect(() => data.saveText(s, d, title: 'x', body: 'y'), _legalError('legal.errors.approved_locked'));
      expect(() => data.fill(s, d), _legalError('legal.errors.approved_locked'));

      expect(() => data.sendToDidox(s, d, number: ' ', date: DateTime.now()), _legalError('legal.errors.number_needed'));
      final docs = data.docs.length;
      final out = data.sendToDidox(s, d, number: 'S-77', date: DateTime(2026, 11, 20));
      expect(d.status, 'sent');
      expect(d.didoxId, out.id);
      expect(out.direction, 'out');
      expect(out.status, 'waiting_partner');
      expect(out.weSigned && out.signatures.single.sandbox, isTrue);
      expect(data.docs.length, docs + 1);
      expect(() => data.sendToDidox(s, d, number: 'S-78', date: DateTime.now()), _legalError('legal.errors.already_sent'));

      data.deleteDraft(s, d);
      expect(data.draft(d.id), isNull);
      for (final a in ['legal_created', 'legal_filled', 'legal_saved', 'legal_approved', 'legal_sent', 'legal_deleted']) {
        expect(s.audit.any((e) => e.action == a), isTrue, reason: a);
      }
    });

    test('a cashier cannot approve or send', () async {
      final s = await _open('cashier@bugalter.test');
      final data = LegalData.of(s);
      final draft = data.drafts.firstWhere((d) => d.status == 'draft');
      expect(() => data.approve(s, draft), _legalError('legal.errors.no_permission'));
      final approved = data.drafts.firstWhere((d) => d.status == 'approved');
      expect(() => data.sendToDidox(s, approved, number: '1', date: DateTime.now()), _legalError('legal.errors.no_permission'));
    });
  });

  test('everything is saved with the books and comes back', () async {
    final s = await _open('owner@bugalter.test');
    final data = LegalData.of(s);
    data.signDoc(s, data.toSign.first);
    final d = data.createDraft(s, 'nda', 'tg');
    await s.flush();

    final again = await AppStore.open();
    final back = LegalData.of(again);
    expect(back.docs.length, data.docs.length);
    expect(back.docs.where((x) => x.status == 'signed').length, data.docs.where((x) => x.status == 'signed').length);
    expect(back.draft(d.id)?.language, 'tg');
    expect(back.docs.first.toJson(), data.docs.first.toJson());
  });

  test('reset demo data starts the Didox demo again', () async {
    final s = await _open('owner@bugalter.test');
    final data = LegalData.of(s);
    data.signAll(s);
    expect(data.toSign, isEmpty);
    await s.resetDemo();
    expect(LegalData.of(s).toSign, isNotEmpty);
  });
}
