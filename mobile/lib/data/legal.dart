import '../core/money.dart';
import '../l10n/strings.dart' show kAllStrings, translate;
import 'models.dart';
import 'server_modules.dart';
import 'store.dart';

/// A business-rule error of the Didox and legal screens: [key] is a full string-table key (`didox.errors.*` or `legal.errors.*`).
class LegalError implements Exception {
  LegalError(this.key);
  final String key;
  @override
  String toString() => key;
}

const String kBlank = '__________';

String _iso(DateTime? d) => d == null ? '' : d.toIso8601String();
DateTime? _dt(dynamic v) => (v == null || v == '') ? null : DateTime.parse(v as String);
String _ddmmyyyy(DateTime d) => '${d.day.toString().padLeft(2, '0')}.${d.month.toString().padLeft(2, '0')}.${d.year}';

// ───────────────────────────────── Didox documents ─────────────────────────────────

class DidoxSignature {
  DidoxSignature({required this.name, this.at, this.ours = false, this.sandbox = false, this.company = '', this.status = ''});

  final String company, status; // server mode: the organisation and the state Didox shows for the signature
  final String name;
  final DateTime? at;
  final bool ours; // signed by this company
  final bool sandbox; // signed on the phone in sandbox mode, not with a real E-imzo key

  Map<String, dynamic> toJson() => {'name': name, 'at': _iso(at), 'ours': ours, 'sandbox': sandbox, 'company': company, 'status': status};

  factory DidoxSignature.fromJson(Map<String, dynamic> j) => DidoxSignature(
        name: (j['name'] ?? '') as String, at: _dt(j['at']), ours: (j['ours'] ?? false) as bool, sandbox: (j['sandbox'] ?? false) as bool,
        company: (j['company'] ?? '') as String, status: (j['status'] ?? '') as String,
      );
}

/// A document of the company in Didox. The live ones are read by the desktop agent on the server; these are demo documents on the device.
class DidoxDoc {
  DidoxDoc({
    required this.id,
    required this.direction,
    required this.kind,
    this.title = '',
    this.number = '',
    required this.docDate,
    required this.counterparty,
    this.tin = '',
    this.contract = '',
    this.amountMinor,
    required this.status,
    List<DocLine>? lines,
    this.body = '',
    this.bodyLang = 'ru',
    Map<String, String>? translations,
    List<DidoxSignature>? signatures,
    List<int>? related,
    this.rejectReason = '',
    this.signApprovedBy = '',
    this.signApprovedAt,
    this.kindText = '',
    this.statusText = '',
    Map<String, String>? summaries,
    this.signState = '',
    this.signError = '',
    this.live = false,
  })  : summaries = summaries ?? {},
        lines = lines ?? [],
        translations = translations ?? {},
        signatures = signatures ?? [],
        related = related ?? [];

  static const statuses = ['waiting_partner', 'waiting_agent', 'waiting_me', 'signed', 'rejected', 'deleted'];
  static const kinds = ['contract', 'invoice', 'act', 'free'];

  final int id;
  final String direction; // in | out
  final String kind; // contract | invoice | act | free

  /// Server mode: a document the phone only reads. The words Didox uses for the kind and the status, the AI summaries kept per language, and
  /// what the robot did with the signature (`queued` | `unknown` | `failed` ...).
  final bool live;
  final String kindText, statusText, signState, signError;
  final Map<String, String> summaries;
  String title, number, tin, contract, counterparty, body, bodyLang, rejectReason, signApprovedBy;
  DateTime docDate;
  DateTime? signApprovedAt;
  int? amountMinor;
  String status;
  List<DocLine> lines;
  Map<String, String> translations; // locale -> the text in that language
  List<DidoxSignature> signatures;
  List<int> related;

  bool get incoming => direction == 'in';
  bool get isOpen => status == 'waiting_partner' || status == 'waiting_agent' || status == 'waiting_me';

  int get linesSubtotal => lines.fold(0, (a, l) => a + l.subtotal);
  int get linesVat => lines.fold(0, (a, l) => a + l.vat);

  /// The amount: from the lines when the document has them, else the amount of its header.
  int? get amount => lines.isNotEmpty ? linesSubtotal + linesVat : amountMinor;

  bool get weSigned => signatures.any((s) => s.ours);
  bool get partnerSigned => signatures.any((s) => !s.ours);

  // what may be done with it (the web's DidoxDocument::canAct): sign or reject what waits for OUR signature; cancel our own unsigned one
  bool get canSign => !live && incoming && status == 'waiting_me';
  bool get canReject => canSign;
  bool get canCancel => !live && !incoming && (status == 'waiting_partner' || status == 'waiting_agent');
  bool get canMakeInvoice => !live && !incoming && status == 'signed' && kind == 'contract';

  /// A real document that waits for OUR signature: it is signed on the web platform, with the E-imzo key.
  bool get waitsForUs => live && incoming && status == 'waiting_me';

  /// The date of the document; null when the server did not say (it is kept as the epoch).
  DateTime? get dateOrNull => docDate.millisecondsSinceEpoch == 0 ? null : docDate;

  /// The text in [locale]: the translation when one is kept, else (for the original language) the original.
  String? textIn(String locale) => translations[locale] ?? (locale == bodyLang && body.isNotEmpty ? body : null);

  Map<String, dynamic> toJson() => {
        'id': id, 'direction': direction, 'kind': kind, 'title': title, 'number': number, 'docDate': _iso(docDate), 'counterparty': counterparty,
        'tin': tin, 'contract': contract, 'amountMinor': amountMinor, 'status': status, 'lines': lines.map((e) => e.toJson()).toList(),
        'body': body, 'bodyLang': bodyLang, 'translations': translations, 'signatures': signatures.map((e) => e.toJson()).toList(),
        'related': related, 'rejectReason': rejectReason, 'signApprovedBy': signApprovedBy, 'signApprovedAt': _iso(signApprovedAt),
        'kindText': kindText, 'statusText': statusText, 'summaries': summaries, 'signState': signState, 'signError': signError, 'live': live,
      };

  factory DidoxDoc.fromJson(Map<String, dynamic> j) => DidoxDoc(
        id: j['id'] as int,
        direction: (j['direction'] ?? 'out') as String,
        kind: (j['kind'] ?? 'free') as String,
        title: (j['title'] ?? '') as String,
        number: (j['number'] ?? '') as String,
        docDate: _dt(j['docDate'])!,
        counterparty: (j['counterparty'] ?? '') as String,
        tin: (j['tin'] ?? '') as String,
        contract: (j['contract'] ?? '') as String,
        amountMinor: j['amountMinor'] as int?,
        status: (j['status'] ?? 'waiting_partner') as String,
        lines: (j['lines'] as List? ?? const []).map((e) => DocLine.fromJson(Map<String, dynamic>.from(e as Map))).toList(),
        body: (j['body'] ?? '') as String,
        bodyLang: (j['bodyLang'] ?? 'ru') as String,
        translations: Map<String, String>.from((j['translations'] as Map?) ?? const {}),
        signatures: (j['signatures'] as List? ?? const []).map((e) => DidoxSignature.fromJson(Map<String, dynamic>.from(e as Map))).toList(),
        related: ((j['related'] as List?) ?? const []).cast<int>(),
        rejectReason: (j['rejectReason'] ?? '') as String,
        signApprovedBy: (j['signApprovedBy'] ?? '') as String,
        signApprovedAt: _dt(j['signApprovedAt']),
        kindText: (j['kindText'] ?? '') as String,
        statusText: (j['statusText'] ?? '') as String,
        summaries: Map<String, String>.from((j['summaries'] as Map?) ?? const {}),
        signState: (j['signState'] ?? '') as String,
        signError: (j['signError'] ?? '') as String,
        live: (j['live'] ?? false) as bool,
      );
}

// ───────────────────────────────── contract templates ─────────────────────────────────

enum FieldKind { text, multiline, money, date, side }

/// A kind of document the lawyer can draft, with the questions it asks. The text is made from the clauses `legal.t.*` in the language asked for.
class LegalTemplate {
  const LegalTemplate({required this.id, required this.kind, required this.fields, required this.required, this.roles, this.clauses = const []});

  final String id;
  final String kind; // the web's kinds: contract | act | agreement | power_of_attorney | letter
  final List<String> fields; // in the order they are asked
  final Set<String> required; // what is reported as missing when empty
  final (String, String)? roles; // (A, B) when the document has two roles and "we" can be either
  final List<(String, String)> clauses; // (heading key, text key), numbered in order; empty for single-body documents

  bool get hasSide => roles != null;
  bool get isContract => clauses.isNotEmpty;

  static const _common = [('h.disputes', 'disputes'), ('h.final', 'final'), ('h.req', 'req')];

  static const all = <LegalTemplate>[
    LegalTemplate(
      id: 'services',
      kind: 'contract',
      roles: ('executor', 'customer'),
      fields: ['number', 'date', 'side', 'party', 'tin', 'addr', 'bank', 'rep', 'subject', 'amount', 'payment', 'term', 'penalty'],
      required: {'party', 'tin', 'subject', 'amount', 'payment', 'term', 'penalty'},
      clauses: [('h.subject', 'services.subject'), ('h.price', 'price'), ('h.term', 'term'), ('h.penalty', 'penalty'), ..._common],
    ),
    LegalTemplate(
      id: 'supply',
      kind: 'contract',
      roles: ('supplier', 'buyer'),
      fields: ['number', 'date', 'side', 'party', 'tin', 'addr', 'bank', 'rep', 'subject', 'delivery', 'amount', 'payment', 'term', 'penalty'],
      required: {'party', 'tin', 'subject', 'delivery', 'amount', 'payment', 'term', 'penalty'},
      clauses: [('h.subject', 'supply.subject'), ('h.price', 'price'), ('h.term', 'term'), ('h.penalty', 'penalty'), ..._common],
    ),
    LegalTemplate(
      id: 'lease',
      kind: 'contract',
      roles: ('lessor', 'lessee'),
      fields: ['number', 'date', 'side', 'party', 'tin', 'addr', 'bank', 'rep', 'subject', 'amount', 'payment', 'term'],
      required: {'party', 'tin', 'subject', 'amount', 'payment', 'term'},
      clauses: [('h.subject', 'lease.subject'), ('h.rent', 'lease.rent'), ('h.term', 'term'), ..._common],
    ),
    LegalTemplate(
      id: 'nda',
      kind: 'contract',
      fields: ['number', 'date', 'party', 'tin', 'addr', 'bank', 'rep', 'subject', 'term', 'penalty'],
      required: {'party', 'tin', 'subject', 'term', 'penalty'},
      clauses: [('h.subject', 'nda.subject'), ('h.obligations', 'nda.obligations'), ('h.penalty', 'penalty'), ..._common],
    ),
    LegalTemplate(
      id: 'agency',
      kind: 'contract',
      roles: ('principal', 'agent'),
      fields: ['number', 'date', 'side', 'party', 'tin', 'addr', 'bank', 'rep', 'subject', 'amount', 'payment', 'term'],
      required: {'party', 'tin', 'subject', 'amount', 'payment', 'term'},
      clauses: [('h.subject', 'agency.subject'), ('h.price', 'price'), ('h.term', 'term'), ..._common],
    ),
    LegalTemplate(
      id: 'employment',
      kind: 'contract',
      fields: ['number', 'date', 'party', 'tin', 'addr', 'bank', 'subject', 'start', 'amount', 'payment', 'term'],
      required: {'party', 'tin', 'subject', 'start', 'amount', 'payment', 'term'},
      clauses: [('h.subject', 'employment.subject'), ('h.pay', 'employment.pay'), ('h.term', 'term'), ..._common],
    ),
    LegalTemplate(
      id: 'act',
      kind: 'act',
      fields: ['number', 'date', 'party', 'tin', 'rep', 'contract_no', 'subject', 'amount'],
      required: {'party', 'tin', 'contract_no', 'subject', 'amount'},
    ),
    LegalTemplate(
      id: 'agreement',
      kind: 'agreement',
      fields: ['number', 'date', 'party', 'tin', 'rep', 'contract_no', 'subject'],
      required: {'party', 'tin', 'contract_no', 'subject'},
    ),
    LegalTemplate(
      id: 'power_of_attorney',
      kind: 'power_of_attorney',
      fields: ['number', 'date', 'representative', 'passport', 'subject', 'term'],
      required: {'representative', 'passport', 'subject', 'term'},
    ),
    LegalTemplate(
      id: 'letter',
      kind: 'letter',
      fields: ['number', 'date', 'party', 'rep', 'subject'],
      required: {'party', 'subject'},
    ),
  ];

  static LegalTemplate byId(String id) => all.firstWhere((t) => t.id == id, orElse: () => all.first);

  /// The label of a question: a template-specific one when the string table has it, else the general one.
  String labelKey(String field) {
    final special = 'legal.q.$field.$id';
    return kAllStrings.containsKey(special) ? special : 'legal.q.$field';
  }

  static FieldKind kindOf(String field) => switch (field) {
        'amount' => FieldKind.money,
        'date' || 'start' => FieldKind.date,
        'subject' => FieldKind.multiline,
        'side' => FieldKind.side,
        _ => FieldKind.text,
      };
}

class DraftText {
  DraftText(this.title, this.body, this.missing);
  final String title, body;
  final List<String> missing; // field names (see LegalTemplate.labelKey)
}

bool _blank(String? s) => s == null || s.trim().isEmpty;

typedef _Party = ({String? name, String? tin, String? rep, String? addr, String? bank});

/// Makes the text of a draft from the template and the answers, in [lang]. Deterministic: nothing is invented; an unknown value stays [kBlank].
DraftText renderDraft({required LegalTemplate t, required String lang, required Company company, required Map<String, String> answers, DateTime? now}) {
  String tr(String key, [Map<String, Object?>? args]) => translate(lang, key, args);
  String v(String? s) => _blank(s) ? kBlank : s!.trim();
  String moneyOf(String? s) {
    final m = int.tryParse(s ?? '');
    return m == null || m <= 0 ? kBlank : formatMoney(m, tr('currency'));
  }

  String dateOf(String? s) {
    final d = _dt(s);
    return d == null ? kBlank : _ddmmyyyy(d);
  }

  final date = _dt(answers['date']) ?? now ?? DateTime.now();
  final _Party ours = (name: company.name, tin: company.stir.replaceAll(RegExp(r'\s'), ''), rep: company.director, addr: company.address, bank: '');
  final _Party other = (name: answers['party'], tin: answers['tin'], rep: answers['rep'], addr: answers['addr'], bank: answers['bank']);
  final weAreB = t.hasSide && answers['side'] == '1';

  // A is the first role of the document. Without roles "we" are always the first party.
  final a = weAreB ? other : ours;
  final b = weAreB ? ours : other;
  final roles = t.roles ?? (t.id == 'employment' ? ('employer', 'employee') : ('party1', 'party2'));

  final args = <String, Object?>{
    'a': tr('legal.t.role.${roles.$1}'),
    'b': tr('legal.t.role.${roles.$2}'),
    'p1': v(a.name),
    'tin1': v(a.tin),
    'rep1': v(a.rep),
    'addr1': v(a.addr),
    'p2': v(b.name),
    'tin2': v(b.tin),
    'rep2': v(b.rep),
    'addr2': v(b.addr),
    'bank2': v(b.bank),
    'subject': v(answers['subject']),
    'amount': moneyOf(answers['amount']),
    'payment': v(answers['payment']),
    'term': v(answers['term']),
    'penalty': v(answers['penalty']),
    'delivery': v(answers['delivery']),
    'start': dateOf(answers['start']),
    'contract_no': v(answers['contract_no']),
    'representative': v(answers['representative']),
    'passport': v(answers['passport']),
    'date': _ddmmyyyy(date),
  };
  // for the single-body documents "us" is always p1 and the other party p2 (the roles above do not apply)
  if (!t.isContract) {
    args['p1'] = v(ours.name);
    args['tin1'] = v(ours.tin);
    args['rep1'] = v(ours.rep);
    args['p2'] = v(other.name);
    args['tin2'] = v(other.tin);
    args['rep2'] = v(other.rep);
  }

  final missing = <String>[
    for (final f in t.fields)
      if (t.required.contains(f) && (f == 'amount' ? moneyOf(answers[f]) == kBlank : _blank(answers[f]))) f,
  ];

  final title = tr('legal.t.${t.id}.title');
  final number = _blank(answers['number']) ? '____' : answers['number']!.trim();
  final head = [tr('legal.t.draft_mark'), title, tr('legal.t.numdate', {'number': number, 'date': _ddmmyyyy(date)})];

  final parts = <String>[];
  if (t.isContract) {
    parts.add(tr('legal.t.preamble', args));
    for (var i = 0; i < t.clauses.length; i++) {
      final c = t.clauses[i];
      parts.add('${i + 1}. ${tr('legal.t.${c.$1}')}\n${tr('legal.t.${c.$2}', args)}');
    }
  } else {
    parts.add(tr('legal.t.${t.id}.body', args));
  }
  return DraftText(title, '${head.join('\n')}\n\n${parts.join('\n\n')}', missing);
}

/// How many blanks (`__________`) a text still has.
int blanksIn(String body) => RegExp(RegExp.escape(kBlank)).allMatches(body).length;

// ───────────────────────────────── drafts ─────────────────────────────────

class LegalDraft {
  LegalDraft({
    required this.id,
    required this.templateId,
    required this.language,
    this.title = '',
    Map<String, String>? answers,
    this.body = '',
    List<String>? missing,
    this.status = 'collecting',
    this.errorKey = '',
    this.counterpartyId,
    this.approvedBy = '',
    this.approvedAt,
    this.didoxId,
    this.sentAt,
    this.actorName = '',
    DateTime? createdAt,
    this.live = false,
    this.errorText = '',
    this.didoxState = '',
    List<Map<String, String>>? filled,
  })  : filled = filled ?? [],
        answers = answers ?? {},
        missing = missing ?? [],
        createdAt = createdAt ?? DateTime.now();

  static const statuses = ['collecting', 'draft', 'approved', 'sent', 'failed'];

  final int id;
  final String templateId; // a template of the phone (demo) or, from the server, the kind of the document: contract | act | agreement | ...

  /// Server mode: a draft the AI lawyer wrote on the platform; the phone only reads it. The server's own words for the error and the state of
  /// its sending to Didox, and the facts the AI says it took from the company's data.
  final bool live;
  final String errorText, didoxState;
  final List<Map<String, String>> filled;
  String language, title, body, status, errorKey, approvedBy, actorName;
  Map<String, String> answers;
  List<String> missing;
  int? counterpartyId, didoxId;
  DateTime? approvedAt, sentAt;
  final DateTime createdAt;

  LegalTemplate get template => LegalTemplate.byId(templateId);
  bool get locked => live || status == 'approved' || status == 'sent';
  String get party => answers['party'] ?? '';

  Map<String, dynamic> toJson() => {
        'id': id, 'templateId': templateId, 'language': language, 'title': title, 'answers': answers, 'body': body, 'missing': missing,
        'status': status, 'errorKey': errorKey, 'counterpartyId': counterpartyId, 'approvedBy': approvedBy, 'approvedAt': _iso(approvedAt),
        'didoxId': didoxId, 'sentAt': _iso(sentAt), 'actorName': actorName, 'createdAt': _iso(createdAt),
        'live': live, 'errorText': errorText, 'didoxState': didoxState, 'filled': filled,
      };

  factory LegalDraft.fromJson(Map<String, dynamic> j) => LegalDraft(
        id: j['id'] as int,
        templateId: j['templateId'] as String,
        language: (j['language'] ?? 'uz') as String,
        title: (j['title'] ?? '') as String,
        answers: Map<String, String>.from((j['answers'] as Map?) ?? const {}),
        body: (j['body'] ?? '') as String,
        missing: ((j['missing'] as List?) ?? const []).cast<String>(),
        status: (j['status'] ?? 'collecting') as String,
        errorKey: (j['errorKey'] ?? '') as String,
        counterpartyId: j['counterpartyId'] as int?,
        approvedBy: (j['approvedBy'] ?? '') as String,
        approvedAt: _dt(j['approvedAt']),
        didoxId: j['didoxId'] as int?,
        sentAt: _dt(j['sentAt']),
        actorName: (j['actorName'] ?? '') as String,
        createdAt: _dt(j['createdAt']),
        live: (j['live'] ?? false) as bool,
        errorText: (j['errorText'] ?? '') as String,
        didoxState: (j['didoxState'] ?? '') as String,
        filled: [for (final e in (j['filled'] as List? ?? const [])) if (e is Map) Map<String, String>.from(e.map((k, v) => MapEntry('$k', '$v')))],
      );
}

// ───────────────────────────────── the data of both screens ─────────────────────────────────

/// Didox documents and the lawyer's drafts, kept in `store.ext['legal']` (saved with the books). Demo data is seeded on first use.
class LegalData {
  LegalData._(this.docs, this.drafts, {this.live = false, this.canSeeDidox = true, this.canSeeLegal = true});

  final List<DidoxDoc> docs;
  final List<LegalDraft> drafts;

  /// Server mode: the real documents and drafts, read-only (every change below throws `server_readonly`). [canSeeDidox] and [canSeeLegal] say
  /// whether the person may see each part (the server leaves a part out of its answer when not).
  final bool live;
  final bool canSeeDidox, canSeeLegal;

  bool get synced => !live || _syncedFlag;
  bool _syncedFlag = true;

  void _ro() {
    if (live) throw AppError('server_readonly');
  }

  static final Expando<LegalData> _cache = Expando<LegalData>('legal-data');
  static final Expando<Object> _seen = Expando<Object>('legal-json');
  static final Object _none = Object();

  /// The data of this store; rebuilt when the stored JSON was replaced (for example by "reset demo data").
  static LegalData of(AppStore s) {
    if (s.serverMode) {
      final server = s.ext['server'];
      final cached = _cache[s];
      if (cached != null && cached.live && identical(_seen[s], server ?? _none)) return cached;
      final j = legalFromServer(s, serverPart(s, 'didox'), serverPart(s, 'legal'));
      final access = Map<String, dynamic>.from(j['access'] as Map);
      final data = LegalData._(
        (j['docs'] as List).map((e) => DidoxDoc.fromJson(Map<String, dynamic>.from(e as Map))).toList(),
        (j['drafts'] as List).map((e) => LegalDraft.fromJson(Map<String, dynamic>.from(e as Map))).toList(),
        live: true,
        canSeeDidox: access['didox'] == true,
        canSeeLegal: access['legal'] == true,
      ).._syncedFlag = serverSynced(s);
      _cache[s] = data;
      _seen[s] = server ?? _none;
      return data;
    }
    final raw = s.ext['legal'];
    final cached = _cache[s];
    if (cached != null && !cached.live && raw != null && identical(_seen[s], raw)) return cached;
    final LegalData data;
    if (raw is Map) {
      final j = Map<String, dynamic>.from(raw);
      data = LegalData._(
        (j['docs'] as List? ?? const []).map((e) => DidoxDoc.fromJson(Map<String, dynamic>.from(e as Map))).toList(),
        (j['drafts'] as List? ?? const []).map((e) => LegalDraft.fromJson(Map<String, dynamic>.from(e as Map))).toList(),
      );
      _cache[s] = data;
      _seen[s] = raw;
    } else {
      data = LegalData._([], []);
      _seed(s, data);
      data._write(s, notify: false);
    }
    return data;
  }

  void _write(AppStore s, {bool notify = true}) {
    final json = <String, dynamic>{'docs': docs.map((e) => e.toJson()).toList(), 'drafts': drafts.map((e) => e.toJson()).toList()};
    s.ext['legal'] = json;
    _cache[s] = this;
    _seen[s] = json;
    if (notify) s.changed();
  }

  void _audit(AppStore s, String action, String subject, {String actor = 'user'}) =>
      s.audit.add(AuditEntry(at: DateTime.now(), actor: actor, action: action, subject: subject));

  void _needApprover(AppStore s, String errorKey) {
    if (!s.role.canApprove) throw LegalError(errorKey);
  }

  // ─────────────── Didox ───────────────

  DidoxDoc? doc(int? id) => docs.where((d) => d.id == id).firstOrNull;

  List<DidoxDoc> sorted() => [...docs]..sort((a, b) {
      final c = b.docDate.compareTo(a.docDate);
      return c != 0 ? c : b.id.compareTo(a.id);
    });

  List<DidoxDoc> get toSign => sorted().where((d) => d.canSign).toList();

  int countStatus(String filter) => switch (filter) {
        'all' => docs.length,
        'open' => docs.where((d) => d.isOpen).length,
        _ => docs.where((d) => d.status == filter).length,
      };

  String _label(DidoxDoc d) => [d.number, d.counterparty].where((e) => e.isNotEmpty).join(' · ');

  void _sign(AppStore s, DidoxDoc d) {
    if (!d.canSign) throw LegalError('didox.errors.not_signable');
    final now = DateTime.now();
    d.status = 'signed';
    d.signApprovedBy = s.user?.name ?? '';
    d.signApprovedAt = now;
    d.signatures.add(DidoxSignature(name: s.company.name.isEmpty ? (s.user?.name ?? '') : s.company.name, at: now, ours: true, sandbox: true));
    _audit(s, 'didox_signed', _label(d));
  }

  /// Signs one document in sandbox mode. A person who may approve must be signed in; the confirm dialog is the UI's job.
  void signDoc(AppStore s, DidoxDoc d) {
    _ro();
    _needApprover(s, 'didox.errors.no_permission');
    _sign(s, d);
    _write(s);
  }

  /// Signs everything that waits for our signature (sandbox). Returns how many.
  int signAll(AppStore s) {
    _ro();
    _needApprover(s, 'didox.errors.no_permission');
    final list = toSign;
    if (list.isEmpty) throw LegalError('didox.errors.nothing_to_sign');
    for (final d in list) {
      _sign(s, d);
    }
    _write(s);
    return list.length;
  }

  void reject(AppStore s, DidoxDoc d, String reason) {
    _ro();
    _needApprover(s, 'didox.errors.no_permission');
    if (!d.canReject) throw LegalError('didox.errors.not_signable');
    d.status = 'rejected';
    d.rejectReason = reason.trim();
    _audit(s, 'didox_rejected', _label(d));
    _write(s);
  }

  void cancel(AppStore s, DidoxDoc d) {
    _ro();
    _needApprover(s, 'didox.errors.no_permission');
    if (!d.canCancel) throw LegalError('didox.errors.not_cancellable');
    d.status = 'deleted';
    _audit(s, 'didox_cancelled', _label(d));
    _write(s);
  }

  /// The books' contractor that matches a Didox counterparty (by tax id, then by name); null when there is none.
  Contractor? matchContractor(AppStore s, DidoxDoc d) {
    final tin = d.tin.replaceAll(RegExp(r'\D'), '');
    if (tin.isNotEmpty) {
      final byTin = s.contractors.where((c) => c.stir.replaceAll(RegExp(r'\D'), '') == tin).firstOrNull;
      if (byTin != null) return byTin;
    }
    return s.contractors.where((c) => c.name.trim().toLowerCase() == d.counterparty.trim().toLowerCase()).firstOrNull;
  }

  /// A new, unsaved invoice or act made from a Didox document: its lines and amounts are copied, nothing is calculated here.
  /// The counterparty is added to the contractors when the books do not have it yet. Returns the document and whether a contractor was added.
  (Doc, bool) prepareBookDoc(AppStore s, DidoxDoc d, DocType type) {
    _ro();
    var added = false;
    var c = matchContractor(s, d);
    if (c == null) {
      c = Contractor(id: s.nextId(), name: d.counterparty, stir: d.tin.replaceAll(RegExp(r'\D'), ''), type: d.incoming ? ContractorType.supplier : ContractorType.customer);
      s.saveContractor(c);
      added = true;
    }
    final today = dateOnly(DateTime.now());
    final lines = d.lines.isNotEmpty
        ? [for (final l in d.lines) DocLine(name: l.name, unit: l.unit, qtyMilli: l.qtyMilli, unitPriceMinor: l.unitPriceMinor, vatBp: l.vatBp)]
        : (d.amountMinor != null && d.amountMinor! > 0
            ? [DocLine(name: d.title.isNotEmpty ? d.title : d.number, unit: '', qtyMilli: 1000, unitPriceMinor: d.amountMinor!, vatBp: 0)]
            : <DocLine>[]);
    final doc = Doc(
      id: s.nextId(),
      type: type,
      number: s.nextNumber(type, today),
      contractorId: c.id,
      issueDate: today,
      dueDate: today.add(const Duration(days: 14)),
      outgoing: !d.incoming,
      lines: lines,
      notes: d.number.isEmpty ? '' : '${d.number}${d.contract.isEmpty ? '' : ' · ${d.contract}'}',
    );
    return (doc, added);
  }

  // ─────────────── drafts ───────────────

  LegalDraft? draft(int? id) => drafts.where((d) => d.id == id).firstOrNull;

  List<LegalDraft> draftsSorted() => [...drafts]..sort((a, b) => b.createdAt.compareTo(a.createdAt));

  LegalDraft createDraft(AppStore s, String templateId, String language) {
    _ro();
    final t = LegalTemplate.byId(templateId);
    final d = LegalDraft(
      id: s.nextId(),
      templateId: t.id,
      language: language,
      answers: {'date': _iso(dateOnly(DateTime.now())), if (t.hasSide) 'side': '0'},
      actorName: s.user?.name ?? '',
    );
    d.title = translate(language, 'legal.t.${t.id}.title');
    drafts.add(d);
    _audit(s, 'legal_created', d.title);
    _write(s);
    return d;
  }

  /// Keeps the answers (and the chosen contractor) of a draft that is not approved yet; the person is back at "collecting".
  void saveAnswers(AppStore s, LegalDraft d, Map<String, String> answers, {int? contractorId, String? language}) {
    _ro();
    if (d.locked) throw LegalError('legal.errors.approved_locked');
    d.answers = {...answers};
    d.counterpartyId = contractorId;
    if (language != null) d.language = language;
    if (d.status != 'collecting') d.status = 'collecting';
    _write(s);
  }

  /// Makes the text from the answers. Needs the other party's name (and the subject) so the text is worth reading; otherwise the draft fails with the reason.
  LegalDraft fill(AppStore s, LegalDraft d) {
    _ro();
    if (d.locked) throw LegalError('legal.errors.approved_locked');
    final t = d.template;
    String? problem;
    if (t.required.contains('party') && _blank(d.answers['party'])) {
      problem = 'legal.errors.need_party';
    } else if (t.required.contains('subject') && _blank(d.answers['subject'])) {
      problem = 'legal.errors.need_subject';
    }
    if (problem != null) {
      d.status = 'failed';
      d.errorKey = problem;
      _write(s);
      return d;
    }
    final r = renderDraft(t: t, lang: d.language, company: s.company, answers: d.answers);
    d.body = r.body;
    d.missing = r.missing;
    d.errorKey = '';
    d.status = 'draft';
    d.title = _blank(d.party) ? r.title : '${r.title} · ${d.party.trim()}';
    _audit(s, 'legal_filled', d.title);
    _write(s);
    return d;
  }

  void saveText(AppStore s, LegalDraft d, {required String title, required String body}) {
    _ro();
    if (d.locked) throw LegalError('legal.errors.approved_locked');
    if (d.status != 'draft') throw LegalError('legal.errors.not_ready');
    d.title = title.trim().isEmpty ? d.title : title.trim();
    d.body = body.trim();
    _audit(s, 'legal_saved', d.title);
    _write(s);
  }

  void approve(AppStore s, LegalDraft d) {
    _ro();
    _needApprover(s, 'legal.errors.no_permission');
    if (d.locked) throw LegalError('legal.errors.approved_locked');
    if (d.status != 'draft' || d.body.trim().isEmpty) throw LegalError('legal.errors.not_ready');
    d.status = 'approved';
    d.approvedBy = s.user?.name ?? '';
    d.approvedAt = DateTime.now();
    _audit(s, 'legal_approved', d.title);
    _write(s);
  }

  /// "Sends" an approved draft to Didox: in this sandbox it adds an outgoing document that waits for the partner, signed by us in sandbox mode.
  DidoxDoc sendToDidox(AppStore s, LegalDraft d, {required String number, required DateTime date}) {
    _ro();
    _needApprover(s, 'legal.errors.no_permission');
    if (d.status == 'sent') throw LegalError('legal.errors.already_sent');
    if (d.status != 'approved') throw LegalError('legal.errors.not_approved');
    if (number.trim().isEmpty) throw LegalError('legal.errors.number_needed');
    final t = d.template;
    final amount = int.tryParse(d.answers['amount'] ?? '');
    final now = DateTime.now();
    final doc = DidoxDoc(
      id: s.nextId(),
      direction: 'out',
      kind: t.kind == 'contract' ? 'contract' : (t.kind == 'act' ? 'act' : 'free'),
      title: d.title,
      number: number.trim(),
      docDate: dateOnly(date),
      counterparty: d.party,
      tin: d.answers['tin'] ?? '',
      contract: d.answers['contract_no'] ?? '',
      amountMinor: amount != null && amount > 0 ? amount : null,
      status: 'waiting_partner',
      body: d.body,
      bodyLang: d.language,
      signatures: [DidoxSignature(name: s.company.name.isEmpty ? (s.user?.name ?? '') : s.company.name, at: now, ours: true, sandbox: true)],
    );
    docs.add(doc);
    d.status = 'sent';
    d.didoxId = doc.id;
    d.sentAt = now;
    _audit(s, 'legal_sent', d.title);
    _write(s);
    return doc;
  }

  void deleteDraft(AppStore s, LegalDraft d) {
    _ro();
    drafts.removeWhere((x) => x.id == d.id);
    _audit(s, 'legal_deleted', d.title);
    _write(s);
  }

  // ─────────────── demo data ───────────────

  static void _seed(AppStore s, LegalData out) {
    final now = dateOnly(DateTime.now());
    DateTime ago(int days) => now.subtract(Duration(days: days));
    final customers = s.contractors.where((c) => c.type != ContractorType.supplier).toList();
    final suppliers = s.contractors.where((c) => c.type != ContractorType.customer).toList();
    ({String name, String tin}) party(List<Contractor> list, int i, String fallback, String fallbackTin) {
      if (list.isEmpty) return (name: fallback, tin: fallbackTin);
      final c = list[i % list.length];
      return (name: c.name, tin: c.stir.replaceAll(RegExp(r'\D'), '').isEmpty ? fallbackTin : c.stir.replaceAll(RegExp(r'\D'), ''));
    }

    final c0 = party(customers, 0, 'Orient Trade MChJ', '301556781');
    final c1 = party(customers, 1, 'Silk Road Logistics', '302884120');
    final c2 = party(customers, 2, 'Samarqand Non QK', '300112905');
    final c3 = party(customers, 3, 'Texno Mir MChJ', '305778342');
    final c4 = party(customers, 4, 'Bukhara Textile', '303410667');
    final s0 = party(suppliers, 0, 'Afrosiyob Ofis Servis', '306221089');
    final s1 = party(suppliers, 1, 'UzEnergo Servis', '207330415');
    final s2 = party(suppliers, 2, 'Navoiy Print', '304998201');

    int? vatBp;
    try {
      vatBp = s.book.rule('vat.standard', now).value;
    } catch (_) {
      vatBp = null; // no rule: the lines carry no VAT (a rate is never guessed)
    }
    DocLine line(String name, int som, {int qty = 1000}) => DocLine(name: name, unit: '', qtyMilli: qty, unitPriceMinor: som * kTiyin, vatBp: vatBp ?? 0);

    DidoxSignature sig(String name, DateTime at, {bool ours = false}) => DidoxSignature(name: name, at: at, ours: ours);
    final us = s.company.name.isEmpty ? 'Demo Savdo MChJ' : s.company.name;

    // a services contract written by the same templates, in three languages, so the document text can be switched
    // (the demo answers are plain contract content typed by a person, per language; they are not tax rates)
    const subjects = {'uz': 'IT-konsalting xizmatlari', 'ru': 'ИТ-консалтинговые услуги', 'tg': 'Хизматҳои машваратии ИТ'};
    const terms = {'uz': '1 oy', 'ru': '1 месяц', 'tg': '1 моҳ'};
    const payments = {'uz': '50% oldindan, 50% topshirilgach', 'ru': '50% предоплата, 50% после сдачи', 'tg': '50% пешпардохт, 50% баъди супоридан'};
    const penalties = {'uz': 'kuniga 0,4%', 'ru': '0,4% в день', 'tg': '0,4% дар як рӯз'};
    Map<String, String> bodies(String partyName, String tin, int som, {required bool weAreExecutor}) => {
          for (final l in const ['uz', 'ru', 'tg'])
            l: renderDraft(
              t: LegalTemplate.byId('services'),
              lang: l,
              company: s.company,
              answers: {
                'date': _iso(now),
                'side': weAreExecutor ? '0' : '1',
                'party': partyName,
                'tin': tin,
                'subject': subjects[l]!,
                'amount': '${som * kTiyin}',
                'payment': payments[l]!,
                'term': terms[l]!,
                'penalty': penalties[l]!,
              },
            ).body,
        };

    DidoxDoc add(DidoxDoc d) {
      out.docs.add(d);
      return d;
    }

    final invOut = add(DidoxDoc(
      id: s.nextId(), direction: 'out', kind: 'invoice', title: 'IT-xizmatlar uchun hisob-faktura', number: 'ESF-2026/41', docDate: ago(12),
      counterparty: c0.name, tin: c0.tin, contract: 'SH-2026/14', status: 'signed', lines: [line('IT-konsalting xizmati', 8400000, qty: 3000)],
      signatures: [sig(us, ago(12), ours: true), sig(c0.name, ago(11))],
    ));
    final contractB = bodies(c0.name, c0.tin, 25200000, weAreExecutor: true);
    add(DidoxDoc(
      id: s.nextId(), direction: 'out', kind: 'contract', title: 'Xizmat koʻrsatish shartnomasi', number: 'SH-2026/14', docDate: ago(40),
      counterparty: c0.name, tin: c0.tin, amountMinor: 25200000 * kTiyin, status: 'signed', body: contractB['uz']!, bodyLang: 'uz', translations: contractB,
      signatures: [sig(us, ago(40), ours: true), sig(c0.name, ago(39))], related: [invOut.id],
    ));
    final incomingB = bodies(s0.name, s0.tin, 18000000, weAreExecutor: false);
    add(DidoxDoc(
      id: s.nextId(), direction: 'in', kind: 'contract', title: 'Договор оказания услуг по обслуживанию офиса', number: 'ДГ-24/118', docDate: ago(2),
      counterparty: s0.name, tin: s0.tin, amountMinor: 18000000 * kTiyin, status: 'waiting_me', body: incomingB['ru']!, bodyLang: 'ru', translations: incomingB,
      signatures: [sig(s0.name, ago(2))],
    ));
    add(DidoxDoc(
      id: s.nextId(), direction: 'in', kind: 'invoice', title: 'Elektr energiya uchun hisob-faktura', number: 'ESF-7781', docDate: ago(3),
      counterparty: s1.name, tin: s1.tin, status: 'waiting_me', lines: [line('Elektr energiya', 2300000)], signatures: [sig(s1.name, ago(3))],
    ));
    add(DidoxDoc(
      id: s.nextId(), direction: 'in', kind: 'act', title: 'Bajarilgan ishlar dalolatnomasi', number: 'A-55', docDate: ago(1), counterparty: s2.name, tin: s2.tin,
      status: 'waiting_me', lines: [line('Bosib chiqarish xizmatlari', 640000, qty: 2000)], signatures: [sig(s2.name, ago(1))],
    ));
    add(DidoxDoc(
      id: s.nextId(), direction: 'out', kind: 'invoice', title: 'Hisob-faktura', number: 'ESF-2026/44', docDate: ago(5), counterparty: c1.name, tin: c1.tin,
      status: 'waiting_partner', lines: [line('Texnik qoʻllab-quvvatlash', 2800000)], signatures: [sig(us, ago(5), ours: true)],
    ));
    add(DidoxDoc(
      id: s.nextId(), direction: 'out', kind: 'act', title: 'Dalolatnoma', number: 'A-2026/19', docDate: ago(6), counterparty: c2.name, tin: c2.tin,
      status: 'waiting_partner', lines: [line('Buxgalteriya xizmati', 4200000)], signatures: [sig(us, ago(6), ours: true)],
    ));
    final contractC = bodies(c4.name, c4.tin, 12000000, weAreExecutor: true);
    add(DidoxDoc(
      id: s.nextId(), direction: 'out', kind: 'contract', title: 'Xizmat koʻrsatish shartnomasi', number: 'SH-2026/22', docDate: ago(8),
      counterparty: c4.name, tin: c4.tin, amountMinor: 12000000 * kTiyin, status: 'waiting_partner', body: contractC['uz']!, bodyLang: 'uz', translations: contractC,
      signatures: [sig(us, ago(8), ours: true)],
    ));
    final contractD = bodies(s0.name, s0.tin, 9000000, weAreExecutor: false);
    add(DidoxDoc(
      id: s.nextId(), direction: 'in', kind: 'contract', title: 'Договор аренды оргтехники', number: 'ДГ-24/071', docDate: ago(45),
      counterparty: s0.name, tin: s0.tin, amountMinor: 9000000 * kTiyin, status: 'signed', body: contractD['ru']!, bodyLang: 'ru', translations: contractD,
      signatures: [sig(s0.name, ago(45)), sig(us, ago(44), ours: true)],
    ));
    add(DidoxDoc(
      id: s.nextId(), direction: 'out', kind: 'free', title: 'Hamkorlik haqida xat', number: 'X-12', docDate: ago(20), counterparty: c3.name, tin: c3.tin,
      status: 'rejected', signatures: [sig(us, ago(20), ours: true)],
    ));
    add(DidoxDoc(
      id: s.nextId(), direction: 'in', kind: 'contract', title: 'Договор печати', number: 'ДГ-24/050', docDate: ago(60), counterparty: s2.name, tin: s2.tin,
      amountMinor: 3000000 * kTiyin, status: 'deleted',
    ));

    // drafts of the lawyer: one approved, one ready with blanks, one still collecting answers
    final approvedAnswers = {
      'date': _iso(ago(9)), 'side': '0', 'number': 'SH-2026/31', 'party': c3.name, 'tin': c3.tin, 'rep': 'Karimov A.', 'addr': 'Toshkent sh.',
      'subject': 'Veb-sayt ishlab chiqish', 'amount': '${14400000 * kTiyin}', 'payment': '50% oldindan, 50% topshirilgach', 'term': '1 oy', 'penalty': 'kuniga 0,4%',
    };
    final r1 = renderDraft(t: LegalTemplate.byId('services'), lang: 'uz', company: s.company, answers: approvedAnswers);
    out.drafts.add(LegalDraft(
      id: s.nextId(), templateId: 'services', language: 'uz', title: '${r1.title} · ${c3.name}', answers: approvedAnswers, body: r1.body, missing: r1.missing,
      status: 'approved', approvedBy: s.user?.name ?? s.company.director, approvedAt: ago(8), actorName: s.company.director, createdAt: ago(9),
    ));
    final readyAnswers = {
      'date': _iso(ago(2)), 'side': '0', 'party': c1.name, 'tin': c1.tin, 'subject': 'Ofis uchun qogʻoz va kanselyariya tovarlari', 'payment': '10 kun ichida',
    };
    final r2 = renderDraft(t: LegalTemplate.byId('supply'), lang: 'ru', company: s.company, answers: readyAnswers);
    out.drafts.add(LegalDraft(
      id: s.nextId(), templateId: 'supply', language: 'ru', title: '${r2.title} · ${c1.name}', answers: readyAnswers, body: r2.body, missing: r2.missing,
      status: 'draft', actorName: s.company.director, createdAt: ago(2),
    ));
    out.drafts.add(LegalDraft(
      id: s.nextId(), templateId: 'nda', language: 'uz', title: translate('uz', 'legal.t.nda.title'), answers: {'date': _iso(now), 'party': c2.name},
      status: 'collecting', actorName: s.company.director, createdAt: now,
    ));
    // seeding is not an action of a person: nothing is written to the audit log
  }
}
