import '../l10n/strings.dart' show kAllStrings, kLocales;
import 'store.dart';

/// The module screens (tax cabinet, letters, payments, Didox, the lawyer, advances, KPI, ERI keys) in SERVER mode.
///
/// The phone keeps the raw answers of `/api/mobile/{cabinet,letters,didox,legal,advances,kpi,signing-keys}` in `store.ext['server']`
/// (a part is missing when the person has no right to it). Here they are turned into the JSON each module already reads, so the
/// screens show the company's real data; nothing is seeded and nothing is written back (the screens are read-only in this mode).
/// Every reader is forgiving: a null, a missing key or a value of an unexpected type becomes "nothing known", never an exception.

// ───────────────────────── reading the raw answers ─────────────────────────

/// Has the phone already read the server (so that "no data" really means no data)?
bool serverSynced(AppStore s) => s.serverMode && s.ext['server'] is Map;

/// One part's raw answer, or null when it was not read or the person may not see it.
Map<String, dynamic>? serverPart(AppStore s, String key) {
  final raw = s.ext['server'];
  if (raw is! Map) return null;
  final part = raw[key];
  return part is Map ? Map<String, dynamic>.from(part) : null;
}

Map<String, dynamic> _map(Object? v) => v is Map ? Map<String, dynamic>.from(v) : <String, dynamic>{};

List<Map<String, dynamic>> _rows(Object? v) => [for (final e in (v is List ? v : const [])) if (e is Map) Map<String, dynamic>.from(e)];

/// A whole number of tiyin (or any count) from whatever the server sent.
int _int(Object? v) => v is int ? v : (v is num ? v.round() : (int.tryParse('${v ?? ''}') ?? 0));

int? _intOrNull(Object? v) => v == null ? null : (v is int ? v : (v is num ? v.round() : int.tryParse('$v')));

String? _text(Object? v) {
  if (v == null) return null;
  final s = '$v'.trim();
  return s.isEmpty ? null : s;
}

String _str(Object? v) => _text(v) ?? '';

/// An ISO date-time string from a 'Y-m-d' or 'Y-m-d\TH:i:s' value; null when it is not one.
String? _iso(Object? v) {
  final s = _text(v);
  if (s == null) return null;
  final d = DateTime.tryParse(s.contains(' ') && !s.contains('T') ? s.replaceFirst(' ', 'T') : s);
  return d?.toIso8601String();
}

DateTime? _date(Object? v) {
  final s = _iso(v);
  return s == null ? null : DateTime.parse(s);
}

DateTime _firstOfMonth(DateTime d) => DateTime(d.year, d.month, 1);

/// The statuses and states the screens know; anything else keeps its raw text on screen.
const _severities = {'error', 'warning', 'info', 'ok'};

// ───────────────────────── tax cabinet ─────────────────────────

/// The last day of the period a finding names (the server sends the first day and the kind: month or quarter).
String? _periodEnd(String? start, String? kind) {
  final s = start == null ? null : DateTime.tryParse(start);
  if (s == null) return null;
  final months = switch (kind) { 'quarter' => 3, 'month' => 1, _ => 0 };
  if (months == 0) return null;
  return DateTime(s.year, s.month + months, 0).toIso8601String();
}

Map<String, dynamic> _finding(Map<String, dynamic> f) {
  final p = _map(f['params']);
  final severity = _severities.contains(f['severity']) ? f['severity'] : 'info';
  final start = _iso(f['period_start']);
  return {
    'key': _str(f['key']),
    'severity': severity,
    'type': _text(f['type']),
    'start': start,
    'end': _periodEnd(start, _text(f['period_kind'])),
    'due': _iso(f['due_date']),
    'reportId': _intOrNull(f['report_id']),
    'tax': _text(p['tax']) ?? _text(p['company']),
    'amount': _intOrNull(p['amount_minor']),
    'count': _intOrNull(p['count']),
    'days': _intOrNull(p['days']),
    'receipt': _text(p['receipt']),
    'portalValue': _text(p['portal'] ?? p['cabinet']),
  };
}

/// The key of a tax of the cabinet that the app has a word for (`cab.tax.<code>`); any other tax is "other" (its own name is kept apart).
String _taxKey(String? code) {
  final c = _text(code);
  return c != null && kAllStrings.containsKey('cab.tax.$c') ? c : 'other';
}

/// The status of a payment started through the platform, in the words of the screens (the robot's `queued`/`sending` are "in progress").
String payStatusFromServer(String? status) => switch (status) {
      'paid' => 'paid',
      'failed' => 'failed',
      'unknown' => 'unknown',
      'submitted' => 'submitted',
      'cancelled' => 'cancelled',
      'checked' => 'checked',
      'queued' || 'sending' => 'queued',
      _ => 'proposed',
    };

/// The cabinet of the company in the module's own JSON. Missing parts give empty lists.
Map<String, dynamic> cabinetFromServer(AppStore s, Map<String, dynamic>? cab, Map<String, dynamic>? letters) {
  final overview = _map(cab?['overview']);
  final totals = _map(overview['totals']);
  final last = cab?['lastCheck'];

  final balances = <Map<String, dynamic>>[];
  for (final t in _rows(overview['taxes'])) {
    final code = _str(t['code']);
    final due = _int(t['remaining_minor']), prepaid = _int(t['overpaid_now_minor']), penalty = _int(t['penalty_minor']), paidYear = _int(t['paid_year_minor']);
    if (code.isEmpty || (t['known'] != true && due == 0 && prepaid == 0 && penalty == 0 && paidYear == 0)) continue;
    balances.add({
      'tax': _taxKey(code), 'name': _text(t['name']), 'asOf': _iso(t['as_of']), 'penalty': penalty, 'dueNow': due, 'prepaidNow': prepaid, 'paidYear': paidYear,
      'paid': _int(t['card_paid_minor']), 'assessed': 0,
    });
  }

  final reports = <Map<String, dynamic>>[];
  for (final r in _rows(overview['reports'])) {
    final start = _iso(r['period_start']);
    if (start == null) continue;
    reports.add({
      'type': _str(r['type']), 'start': start, 'end': _iso(r['period_end']) ?? start, 'status': _str(r['status']).isEmpty ? 'submitted' : _str(r['status']),
      'submittedAt': _iso(r['submitted_at']), 'receipt': _str(r['receipt']), 'name': _str(r['name']),
    });
  }

  final orders = <Map<String, dynamic>>[];
  for (final o in _rows(cab?['orders'])) {
    final year = int.tryParse(_str(o['year']));
    final date = _iso(o['date']) ?? (year == null ? null : DateTime(year).toIso8601String());
    if (date == null) continue;
    orders.add({
      'number': _str(o['number']), 'date': date, 'tax': _taxKey(_text(o['taxCode']) ?? _text(o['tax'])), 'kind': '', 'amount': _int(o['amountMinor']),
      'paid': o['paid'] == true, 'statusText': _str(o['statusText']),
    });
  }

  final payments = <Map<String, dynamic>>[];
  for (final p in _rows(cab?['payments'])) {
    final status = payStatusFromServer(_text(p['status']));
    final by = _str(p['approvedBy']);
    final created = _iso(p['createdAt']);
    final approvedAt = _iso(p['approvedAt']);
    payments.add({
      'id': _int(p['id']), 'tax': _taxKey(_text(p['taxCode'])), 'kind': _str(p['kind']), 'amount': _int(p['amountMinor']), 'purpose': _str(p['purpose']),
      'status': status, 'proposedBy': 'user', 'approvedBy': by, 'approvedAt': approvedAt, 'orderNumber': _str(p['orderNumber']),
      'dryRun': p['dryRun'] == true, 'error': _str(p['error']),
      'history': [
        if (created != null) {'at': created, 'status': 'proposed', 'actor': by},
        if (approvedAt != null && status != 'proposed') {'at': approvedAt, 'status': status, 'actor': by},
      ],
    });
  }

  final checks = <Map<String, dynamic>>[];
  if (last is Map) {
    final c = Map<String, dynamic>.from(last);
    final at = _iso(c['finishedAt']) ?? _iso(c['startedAt']);
    if (at != null) {
      final sum = _map(c['summary']);
      checks.add({
        'at': at, 'by': _str(c['actor']), 'findings': [for (final f in _rows(c['findings'])) _finding(f)], 'submitted': _int(sum['submitted']),
        'missing': _int(sum['missing']), 'overdue': _int(sum['overdue']), 'rejected': _int(sum['rejected']), 'status': _str(c['status']), 'error': _str(c['error']),
      });
    }
  }

  // letters: the opened ones (with text) come from /letters; their "unread" flag from the cabinet's inbox in the overview
  final inbox = _rows(overview['letters']);
  bool unreadFor(String number, String kind, String title) {
    for (final m in inbox) {
      final sameNumber = number.isNotEmpty && _str(m['number']) == number;
      final sameTitle = title.isNotEmpty && _str(m['title']) == title;
      if ((sameNumber || sameTitle) && (m['kind'] == null || _str(m['kind']) == kind || sameTitle)) return m['unread'] == true;
    }
    return false;
  }

  final letterRows = <Map<String, dynamic>>[];
  final seenNumbers = <String>{};
  for (final l in _rows(letters?['letters'])) {
    final number = _str(l['number']);
    final kind = _str(l['kind']) == 'requirement' ? 'requirement' : 'notice';
    final tr = _map(l['translations']);
    String tt(String loc, String field) => _str(_map(tr[loc])[field]);
    final titleUz = _str(l['titleUz']), titleRu = _str(l['titleRu']);
    final body = _str(l['body']);
    String pick(List<String> options) => options.firstWhere((e) => e.isNotEmpty, orElse: () => '');
    final title = {
      'uz': pick([titleUz, tt('uz', 'title'), titleRu, tt('tg', 'title')]),
      'ru': pick([titleRu, tt('ru', 'title'), titleUz, tt('tg', 'title')]),
      'tg': pick([tt('tg', 'title'), titleRu, titleUz]),
    };
    final text = {for (final loc in kLocales) loc: pick([tt(loc, 'body'), body]), if (body.isNotEmpty) 'orig': body};
    final summary = {for (final loc in kLocales) if (tt(loc, 'summary').isNotEmpty) loc: tt(loc, 'summary')};
    final translated = [for (final loc in kLocales) if (tt(loc, 'body').isNotEmpty || (loc == 'tg' && tt('tg', 'title').isNotEmpty)) loc];
    final date = _iso(l['date']) ?? _iso(l['firstSeenAt']) ?? DateTime.now().toIso8601String();
    if (number.isNotEmpty) seenNumbers.add('$kind|$number');
    final shown = title['ru']!.isNotEmpty ? title['ru']! : title['uz']!;
    letterRows.add({
      'id': _int(l['id']), 'kind': kind, 'number': number, 'date': date, 'deadline': _iso(l['deadline']), 'answered': l['answered'] == true,
      'unread': unreadFor(number, kind, shown) || unreadFor(number, kind, title['uz']!), 'ask': 'info', 'amount': null, 'tax': null, 'reportType': null,
      'periodStart': null, 'periodEnd': null, 'title': title, 'body': text, 'summary': summary, 'translated': translated, 'hasPdf': l['hasPdf'] == true,
    });
  }
  // inbox messages whose text was not opened by the robot: only the title and the dates are known
  var synthetic = 0;
  for (final m in inbox) {
    final number = _str(m['number']);
    final kind = _str(m['kind']) == 'requirement' ? 'requirement' : 'notice';
    if (number.isNotEmpty && seenNumbers.contains('$kind|$number')) continue;
    final title = _str(m['title']);
    if (title.isEmpty) continue;
    if (number.isEmpty && letterRows.any((r) => (r['title'] as Map).values.contains(title))) continue;
    letterRows.add({
      'id': -(++synthetic), 'kind': kind, 'number': number, 'date': _iso(m['date']) ?? DateTime.now().toIso8601String(), 'deadline': _iso(m['deadline']),
      'answered': false, 'unread': m['unread'] == true, 'ask': 'info', 'amount': null, 'tax': null, 'reportType': null, 'periodStart': null, 'periodEnd': null,
      'title': {'uz': title, 'ru': title, 'tg': title}, 'body': <String, String>{}, 'summary': <String, String>{}, 'translated': <String>[], 'hasPdf': false,
    });
  }

  return {
    'live': true,
    'access': {'cabinet': cab != null, 'letters': letters != null},
    'readAt': _iso(cab?['readAt']),
    'profile': <String, dynamic>{},
    'balances': balances,
    'reports': reports,
    'orders': orders,
    'payments': payments,
    'checks': checks,
    'runs': <Map<String, dynamic>>[],
    'letters': letterRows,
    'totals': {
      'debt': _int(totals['remaining_minor']), 'penalty': _int(totals['penalty_minor']), 'overpaid': _int(totals['overpaid_minor']),
      'paidYear': _int(totals['paid_year_minor']),
    },
  };
}

// ───────────────────────── Didox and the lawyer ─────────────────────────

/// The document kind of the screens from the words Didox uses ("Шартнома", "Ҳисоб-фактура", "Далолатнома", …).
String didoxKindFromText(String text) {
  final t = text.toLowerCase();
  if (t.contains('шартнома') || t.contains('shartnoma') || t.contains('договор') || t.contains('contract')) return 'contract';
  if (t.contains('фактура') || t.contains('faktura') || t.contains('счёт') || t.contains('счет') || t.contains('invoice')) return 'invoice';
  if (t.contains('далолатнома') || t.contains('dalolatnoma') || t.contains('акт') || t.contains('act')) return 'act';
  return 'free';
}

/// The language a text is written in, from its letters (the server does not say): Latin = Uzbek, Tajik letters = Tajik, Uzbek letters = Uzbek, else Russian.
String guessTextLanguage(String text) {
  if (text.isEmpty) return 'ru';
  final sample = text.length > 4000 ? text.substring(0, 4000) : text;
  final latin = RegExp(r'[A-Za-z]').allMatches(sample).length;
  final cyr = RegExp(r'[А-Яа-яЁёЎўҚқҒғҲҳҶҷӢӣӮӯ]').allMatches(sample).length;
  if (latin > cyr) return 'uz';
  if (RegExp(r'[ҶҷӢӣӮӯ]').hasMatch(sample)) return 'tg';
  if (RegExp(r'[ЎўҚқҒғҲҳ]').hasMatch(sample)) return 'uz';
  return 'ru';
}

const _didoxStatuses = {'waiting_partner', 'waiting_agent', 'waiting_me', 'signed', 'rejected', 'deleted'};

Map<String, dynamic> didoxDocFromServer(AppStore s, Map<String, dynamic> d) {
  final kindText = _str(d['kind']);
  final body = _str(d['body']);
  final tr = _map(d['translations']);
  final texts = <String, String>{}, summaries = <String, String>{};
  for (final loc in kLocales) {
    final t = _map(tr[loc]);
    if (_str(t['body']).isNotEmpty) texts[loc] = _str(t['body']);
    if (_str(t['summary']).isNotEmpty) summaries[loc] = _str(t['summary']);
  }
  final ourTin = s.company.stir.replaceAll(RegExp(r'\D'), '');
  final ourName = s.company.name.trim().toLowerCase();
  final signatures = <Map<String, dynamic>>[];
  for (final sg in _rows(d['signatures'])) {
    final company = _str(sg['company']).toLowerCase();
    final id = _str(sg['id_number']);
    final ours = (ourTin.isNotEmpty && id == ourTin) || (ourName.isNotEmpty && company.isNotEmpty && (company.contains(ourName) || ourName.contains(company)));
    signatures.add({'name': _str(sg['name']), 'at': _iso(sg['signed_at']), 'ours': ours, 'sandbox': false, 'company': _str(sg['company']), 'status': _str(sg['status'])});
  }
  final amount = _int(d['amountMinor']);
  final status = _str(d['status']);
  return {
    'id': _int(d['id']),
    'direction': _str(d['direction']) == 'in' ? 'in' : 'out',
    'kind': didoxKindFromText(kindText),
    'kindText': kindText,
    'title': _str(d['title']),
    'number': _str(d['number']),
    'docDate': _iso(d['date']) ?? DateTime.fromMillisecondsSinceEpoch(0).toIso8601String(),
    'counterparty': _str(d['counterparty']),
    'tin': _str(d['counterpartyTin']),
    'contract': _str(d['contract']),
    'amountMinor': amount > 0 ? amount : null,
    'status': _didoxStatuses.contains(status) ? status : (status.isEmpty ? 'waiting_partner' : status),
    'statusText': _str(d['statusText']),
    'lines': <Map<String, dynamic>>[],
    'body': body,
    'bodyLang': guessTextLanguage(body),
    'translations': texts,
    'summaries': summaries,
    'signatures': signatures,
    'related': <int>[],
    'rejectReason': _str(d['signReason']),
    'signApprovedBy': '',
    'signApprovedAt': _iso(d['signApprovedAt']),
    'signState': _str(d['signState']),
    'signError': _str(d['signError']),
    'live': true,
  };
}

const _draftStatuses = {'draft', 'approved', 'failed'};

Map<String, dynamic> legalDraftFromServer(Map<String, dynamic> d) {
  final status = _str(d['status']);
  final didoxState = _str(d['didoxState']);
  final amount = _int(d['amountMinor']);
  final answers = <String, String>{
    if (_str(d['counterparty']).isNotEmpty) 'party': _str(d['counterparty']),
    if (_str(d['counterpartyTin']).isNotEmpty) 'tin': _str(d['counterpartyTin']),
    if (amount > 0) 'amount': '$amount',
  };
  final mapped = didoxState == 'sent' ? 'sent' : (_draftStatuses.contains(status) ? status : (status == 'writing' || status == 'filling' ? 'writing' : 'draft'));
  final filled = <Map<String, String>>[
    for (final f in _rows(d['filled'])) {'fact': _str(f['fact']), 'value': _str(f['value'])},
  ];
  return {
    'id': _int(d['id']),
    'templateId': _str(d['kind']).isEmpty ? 'other' : _str(d['kind']),
    'language': kLocales.contains(d['language']) ? d['language'] : 'uz',
    'title': _str(d['title']),
    'answers': answers,
    'body': _str(d['body']),
    'missing': [for (final m in (d['missing'] is List ? d['missing'] as List : const [])) if (m is String && m.trim().isNotEmpty) m.trim()],
    'filled': filled,
    'status': mapped,
    'errorKey': '',
    'errorText': _str(d['error']),
    'counterpartyId': null,
    'approvedBy': _str(d['approvedBy']),
    'approvedAt': _iso(d['approvedAt']),
    'didoxId': null,
    'didoxState': didoxState,
    'sentAt': _iso(d['didoxAt']),
    'actorName': _str(d['actor']),
    'createdAt': _iso(d['createdAt']) ?? DateTime.now().toIso8601String(),
    'live': true,
  };
}

Map<String, dynamic> legalFromServer(AppStore s, Map<String, dynamic>? didox, Map<String, dynamic>? legal) => {
      'live': true,
      'access': {'didox': didox != null, 'legal': legal != null},
      'docs': [for (final d in _rows(didox?['documents'])) didoxDocFromServer(s, d)],
      'drafts': [for (final d in _rows(legal?['drafts'])) legalDraftFromServer(d)],
    };

// ───────────────────────── advances, KPI, ERI keys ─────────────────────────

Map<String, dynamic> extrasFromServer(AppStore s) {
  final adv = serverPart(s, 'advances');
  final kpi = serverPart(s, 'kpi');
  final keys = serverPart(s, 'signing-keys');
  final advances = <Map<String, dynamic>>[];
  for (final a in _rows(adv?['advances'])) {
    final given = _iso(a['givenOn']);
    final period = _date(a['period']) ?? _date(a['givenOn']);
    if (given == null || period == null) continue;
    advances.add({
      'id': _int(a['id']), 'employeeId': _int(a['employeeId']), 'employeeName': _str(a['employee']), 'period': _firstOfMonth(period).toIso8601String(), 'givenOn': given,
      'amountMinor': _int(a['amountMinor']), 'accountId': 0, 'txnId': _intOrNull(a['transactionId']), 'note': _str(a['note']),
    });
  }
  final bonuses = <Map<String, dynamic>>[];
  for (final b in _rows(kpi?['bonuses'])) {
    final period = _date(b['period']);
    if (period == null) continue;
    bonuses.add({
      'id': _int(b['id']), 'employeeId': _int(b['employeeId']), 'employeeName': _str(b['employee']), 'period': _firstOfMonth(period).toIso8601String(),
      'title': _str(b['title']), 'maxBonusMinor': _int(b['maxBonusMinor']), 'achievementBp': _int(b['achievementBp']), 'note': _str(b['note']),
    });
  }
  final list = <Map<String, dynamic>>[];
  for (final k in _rows(keys?['keys'])) {
    final to = _date(k['validTo']);
    final from = _date(k['validFrom']) ?? to;
    if (to == null || from == null) continue;
    final revoked = _text(k['revokedAt']) != null || _str(k['status']) == 'revoked';
    list.add({
      'id': _int(k['id']), 'label': _str(k['label']), 'ownerName': _str(k['owner']), 'ownerTin': _str(k['ownerTin']), 'serial': _str(k['serial']),
      'validFrom': from.toIso8601String(), 'validTo': to.toIso8601String(), 'revoked': revoked,
    });
  }
  return {
    'live': true,
    'access': {'advances': adv != null, 'kpi': kpi != null, 'keys': keys != null},
    'advances': advances,
    'kpi': bonuses,
    'keys': list,
  };
}
