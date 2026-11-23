import '../core/money.dart';
import '../l10n/strings.dart';
import 'models.dart';
import 'server_modules.dart';
import 'store.dart';
import 'tax.dart';

/// The tax cabinet side of the platform. In DEMO mode (sandbox data kept on this device, in `store.ext['cabinet']`): the snapshot is seeded
/// from the company's own books, "check now" re-runs the deterministic comparison of the books against that snapshot (a port of
/// `App\Portal\PortalAnalyzer`), and a payment only records a sandbox receipt. In SERVER mode ([CabinetData.live]) everything is what the
/// platform's robot read from the real cabinet (`store.ext['server']`), shown read-only: nothing is seeded, checked or paid from the phone.
/// Nothing here talks to my.soliq.uz. Amounts are integer tiyin; tax amounts come only from
/// [AppStore.compute] and the rules table; the AI can only PROPOSE a payment, a person presses "Pay".

/// The taxes of the personal account (the web's PortalSnapshot::TAXES, the ones a company here can owe).
const List<String> kCabTaxes = ['vat', 'turnover', 'profit', 'pit', 'social'];

/// The cabinet's payment types (PaymentCatalog::KINDS).
const List<String> kPayKinds = ['08101', '08102', '08103', '08105', '08108'];

/// The largest amount the cabinet accepts: "less than 1 trillion so'm".
const int kMaxPayMinor = 1000000000000 * 100 - 1;

enum PayStatus { proposed, checked, paid, unknown, failed, cancelled, queued, submitted }

enum Severity { error, warning, info, ok }

DateTime? _dt(dynamic v) => v == null ? null : DateTime.parse(v as String);
String? _st(DateTime? d) => d?.toIso8601String();
DateTime _day(DateTime d) => DateTime(d.year, d.month, d.day);

// ───────────────────────── snapshot rows ─────────────────────────

class TaxBalance {
  TaxBalance({required this.tax, this.assessed = 0, this.paid = 0, this.penalty = 0, this.name, this.asOf, this.dueNow, this.prepaidNow, this.paidYear});

  final String tax; // a key of [kCabTaxes] in the demo; the cabinet's own code ('38', '100', ...) or name from the server
  int assessed, paid, penalty;

  /// Server mode only: the cabinet's own name of the tax, the day of its card, what is owed now (debt + penalty less what was paid since the
  /// card, the web's headline figure), what is prepaid now, and what was paid since 1 January.
  final String? name;
  final DateTime? asOf;
  final int? dueNow, prepaidNow, paidYear;

  bool get live => dueNow != null;

  int get debt => dueNow ?? (assessed > paid ? assessed - paid : 0);
  int get overpaid => prepaidNow ?? (paid > assessed ? paid - assessed : 0);

  Map<String, dynamic> toJson() => {'tax': tax, 'assessed': assessed, 'paid': paid, 'penalty': penalty};

  factory TaxBalance.fromJson(Map<String, dynamic> j) => TaxBalance(
        tax: j['tax'] as String, assessed: (j['assessed'] ?? 0) as int, paid: (j['paid'] ?? 0) as int, penalty: (j['penalty'] ?? 0) as int,
        name: j['name'] as String?, asOf: _dt(j['asOf']), dueNow: j['dueNow'] as int?, prepaidNow: j['prepaidNow'] as int?, paidYear: j['paidYear'] as int?,
      );
}

/// A report as the cabinet's journal lists it.
class CabReport {
  CabReport({required this.type, required this.start, required this.end, this.status = 'accepted', this.submittedAt, this.receipt = '', this.name = ''});

  final String name; // the cabinet's own name of the report (server mode)
  final String type; // TaxType name; '' when the cabinet's report is of a kind the app does not know
  final DateTime start, end;
  final String status; // accepted | submitted | rejected | draft
  final DateTime? submittedAt;
  final String receipt;

  Map<String, dynamic> toJson() => {'type': type, 'start': _st(start), 'end': _st(end), 'status': status, 'submittedAt': _st(submittedAt), 'receipt': receipt};

  factory CabReport.fromJson(Map<String, dynamic> j) => CabReport(
        type: j['type'] as String, start: _dt(j['start'])!, end: _dt(j['end'])!, status: (j['status'] ?? 'accepted') as String,
        submittedAt: _dt(j['submittedAt']), receipt: (j['receipt'] ?? '') as String, name: (j['name'] ?? '') as String,
      );
}

/// A payment order as the cabinet lists it.
class CabOrder {
  CabOrder({required this.number, required this.date, required this.tax, required this.kind, required this.amount, this.paid = true, this.statusText = ''});

  final String statusText; // the cabinet's own words for the state of the order (server mode)
  final String number;
  final DateTime date;
  final String tax, kind;
  final int amount;
  final bool paid;

  Map<String, dynamic> toJson() => {'number': number, 'date': _st(date), 'tax': tax, 'kind': kind, 'amount': amount, 'paid': paid};

  factory CabOrder.fromJson(Map<String, dynamic> j) => CabOrder(
        number: j['number'] as String, date: _dt(j['date'])!, tax: j['tax'] as String, kind: (j['kind'] ?? '08102') as String,
        amount: j['amount'] as int, paid: (j['paid'] ?? true) as bool, statusText: (j['statusText'] ?? '') as String,
      );
}

/// A letter of the tax authority (demand or notice) with its text in each language. The original is Uzbek or Russian; Tajik is a translation.
class CabLetter {
  CabLetter({
    required this.id,
    required this.kind,
    required this.number,
    required this.date,
    this.deadline,
    this.answered = false,
    this.unread = true,
    required this.ask,
    this.amount,
    this.tax,
    this.reportType,
    this.periodStart,
    this.periodEnd,
    required this.title,
    required this.body,
    this.summary = const {},
    this.translated,
    this.live = false,
  });

  final int id;
  final String kind; // requirement | notice
  final String number;
  final DateTime date;
  final DateTime? deadline;
  bool answered, unread;

  /// What the letter asks for: pay_debt | file_report | documents | info. Structured, so the explanation never has to guess numbers.
  final String ask;
  final int? amount;
  final String? tax, reportType;
  final DateTime? periodStart, periodEnd;
  final Map<String, String> title, body; // uz, ru, tg

  /// Server mode: the kept AI summary of the letter per language, and the languages whose text is a translation of the cabinet's original.
  final Map<String, String> summary;
  final List<String>? translated;
  final bool live;

  bool get isDemand => kind == 'requirement';

  String titleIn(String locale) => _first([title[locale], title['ru'], title['uz']]);
  String bodyIn(String locale) => _first([body[locale], body['ru'], body['uz']]);
  String? summaryIn(String locale) => summary[locale];

  static String _first(List<String?> options) => options.firstWhere((e) => e != null && e.isNotEmpty, orElse: () => '') ?? '';

  /// Tajik is an AI translation of the cabinet's text; the original (Uzbek) is shown next to it. From the server: the languages that came with a translation.
  bool isTranslation(String locale) => translated != null ? translated!.contains(locale) : locale == 'tg';

  bool isOverdue(DateTime now) => deadline != null && !answered && _day(deadline!).isBefore(_day(now));

  Map<String, dynamic> toJson() => {
        'id': id, 'kind': kind, 'number': number, 'date': _st(date), 'deadline': _st(deadline), 'answered': answered, 'unread': unread, 'ask': ask,
        'amount': amount, 'tax': tax, 'reportType': reportType, 'periodStart': _st(periodStart), 'periodEnd': _st(periodEnd), 'title': title, 'body': body,
      };

  factory CabLetter.fromJson(Map<String, dynamic> j) => CabLetter(
        id: j['id'] as int, kind: j['kind'] as String, number: (j['number'] ?? '') as String, date: _dt(j['date'])!, deadline: _dt(j['deadline']),
        answered: (j['answered'] ?? false) as bool, unread: (j['unread'] ?? false) as bool, ask: (j['ask'] ?? 'info') as String,
        amount: j['amount'] as int?, tax: j['tax'] as String?, reportType: j['reportType'] as String?, periodStart: _dt(j['periodStart']),
        periodEnd: _dt(j['periodEnd']), title: Map<String, String>.from(j['title'] as Map), body: Map<String, String>.from(j['body'] as Map),
        summary: Map<String, String>.from((j['summary'] as Map?) ?? const {}), translated: (j['translated'] as List?)?.cast<String>(), live: (j['live'] ?? false) as bool,
      );
}

/// What the explanation card of a letter says, built only from the letter's structured fields.
class LetterInsight {
  LetterInsight({required this.ask, this.amount, this.tax, this.deadline, this.daysLeft, this.reportType, this.periodStart, this.periodEnd, required this.steps});

  final String ask;
  final int? amount;
  final String? tax, reportType;
  final DateTime? deadline, periodStart, periodEnd;
  final int? daysLeft;
  final List<String> steps; // keys: pay, report, documents, none
}

// ───────────────────────── payments I started here ─────────────────────────

class PayEvent {
  PayEvent(this.at, this.status, this.actor);
  final DateTime at;
  final String status, actor;
  Map<String, dynamic> toJson() => {'at': _st(at), 'status': status, 'actor': actor};
  factory PayEvent.fromJson(Map<String, dynamic> j) => PayEvent(_dt(j['at'])!, j['status'] as String, (j['actor'] ?? '') as String);
}

/// A tax payment. It is only PROPOSED (by the form or the assistant); a person presses "Pay"; the approval is bound to tax + type + amount.
class CabPayment {
  CabPayment({
    required this.id,
    required this.tax,
    required this.kind,
    required this.amount,
    this.purpose = '',
    this.status = PayStatus.proposed,
    required this.proposedBy,
    this.approvedBy = '',
    this.approvedAt,
    this.orderNumber = '',
    List<PayEvent>? history,
    this.dryRun = false,
    this.error = '',
  }) : history = history ?? [];

  final int id;
  final String tax, kind;
  final int amount;

  /// Server mode: a check that moved no money, and the cabinet's or the robot's own words when it failed.
  final bool dryRun;
  final String error;
  String purpose;
  PayStatus status;
  final String proposedBy; // user | ai
  String approvedBy, orderNumber;
  DateTime? approvedAt;
  final List<PayEvent> history;

  /// What the approval is bound to: nothing else may be paid under it.
  String get binding => '$tax|$kind|$amount';

  bool get open => status == PayStatus.proposed || status == PayStatus.checked;

  Map<String, dynamic> toJson() => {
        'id': id, 'tax': tax, 'kind': kind, 'amount': amount, 'purpose': purpose, 'status': status.name, 'proposedBy': proposedBy,
        'approvedBy': approvedBy, 'approvedAt': _st(approvedAt), 'orderNumber': orderNumber, 'history': history.map((e) => e.toJson()).toList(),
        'dryRun': dryRun, 'error': error,
      };

  factory CabPayment.fromJson(Map<String, dynamic> j) => CabPayment(
        id: j['id'] as int, tax: j['tax'] as String, kind: (j['kind'] ?? '') as String, amount: j['amount'] as int, purpose: (j['purpose'] ?? '') as String,
        status: PayStatus.values.where((e) => e.name == j['status']).firstOrNull ?? PayStatus.proposed, proposedBy: (j['proposedBy'] ?? 'user') as String,
        dryRun: (j['dryRun'] ?? false) as bool, error: (j['error'] ?? '') as String,
        approvedBy: (j['approvedBy'] ?? '') as String, approvedAt: _dt(j['approvedAt']), orderNumber: (j['orderNumber'] ?? '') as String,
        history: [for (final e in (j['history'] as List? ?? const [])) PayEvent.fromJson(Map<String, dynamic>.from(e as Map))],
      );
}

/// One line of "pay everything due".
class PlanItem {
  const PlanItem(this.tax, this.kind, this.amount);
  final String tax, kind;
  final int amount;
}

// ───────────────────────── analysis ─────────────────────────

class CabFinding {
  CabFinding({required this.key, required this.severity, this.type, this.start, this.end, this.due, this.reportId, this.tax, this.amount, this.count, this.days, this.receipt, this.portalValue});

  final String key; // tin_mismatch, vat_status_mismatch, regime_mismatch, submitted, rejected, overdue, missing, debt, penalty, overpaid, unread_messages, local_not_on_portal
  final Severity severity;
  final String? type, tax, receipt, portalValue;
  final DateTime? start, end, due;
  final int? reportId, amount, count, days;

  Map<String, dynamic> toJson() => {
        'key': key, 'severity': severity.name, 'type': type, 'start': _st(start), 'end': _st(end), 'due': _st(due), 'reportId': reportId, 'tax': tax,
        'amount': amount, 'count': count, 'days': days, 'receipt': receipt, 'portalValue': portalValue,
      };

  factory CabFinding.fromJson(Map<String, dynamic> j) => CabFinding(
        key: j['key'] as String, severity: Severity.values.where((e) => e.name == j['severity']).firstOrNull ?? Severity.info, type: j['type'] as String?, start: _dt(j['start']),
        end: _dt(j['end']), due: _dt(j['due']), reportId: j['reportId'] as int?, tax: j['tax'] as String?, amount: j['amount'] as int?,
        count: j['count'] as int?, days: j['days'] as int?, receipt: j['receipt'] as String?, portalValue: j['portalValue'] as String?,
      );
}

class CabCheck {
  CabCheck({required this.at, required this.by, required this.findings, required this.submitted, required this.missing, required this.overdue, required this.rejected, this.error = ''});

  final String error; // server mode: why the robot's last read of the cabinet failed
  final DateTime at;
  final String by;
  final List<CabFinding> findings;
  final int submitted, missing, overdue, rejected;

  Map<String, dynamic> toJson() => {
        'at': _st(at), 'by': by, 'findings': findings.map((e) => e.toJson()).toList(), 'submitted': submitted, 'missing': missing, 'overdue': overdue, 'rejected': rejected,
      };

  factory CabCheck.fromJson(Map<String, dynamic> j) => CabCheck(
        at: _dt(j['at'])!, by: (j['by'] ?? '') as String,
        findings: [for (final e in (j['findings'] as List)) CabFinding.fromJson(Map<String, dynamic>.from(e as Map))],
        submitted: j['submitted'] as int, missing: j['missing'] as int, overdue: j['overdue'] as int, rejected: j['rejected'] as int, error: (j['error'] ?? '') as String,
      );
}

// ───────────────────────── automatic preparation ─────────────────────────

class RunStep {
  RunStep(this.key, this.status, this.params);
  final String key; // profile, portal, obligations, data, prepare, validate
  final String status; // done | skipped
  final Map<String, String> params;
  Map<String, dynamic> toJson() => {'key': key, 'status': status, 'params': params};
  factory RunStep.fromJson(Map<String, dynamic> j) => RunStep(j['key'] as String, j['status'] as String, Map<String, String>.from(j['params'] as Map));
}

/// One report of a run. The `version` is what the person reviewed: if the report changes afterwards, the approval is refused.
class RunRow {
  RunRow({required this.type, required this.start, required this.end, required this.due, this.reportId, this.onCabinet = false, this.problems = const [], this.warnings = const [], this.version = ''});

  final TaxType type;
  final DateTime start, end, due;
  final int? reportId;
  final bool onCabinet;
  final List<String> problems, warnings; // keys: no_books, no_tin, unverified_rules, overdue, unclassified
  final String version;

  Map<String, dynamic> toJson() => {
        'type': type.name, 'start': _st(start), 'end': _st(end), 'due': _st(due), 'reportId': reportId, 'onCabinet': onCabinet,
        'problems': problems, 'warnings': warnings, 'version': version,
      };

  factory RunRow.fromJson(Map<String, dynamic> j) => RunRow(
        type: TaxType.values.byName(j['type'] as String), start: _dt(j['start'])!, end: _dt(j['end'])!, due: _dt(j['due'])!, reportId: j['reportId'] as int?,
        onCabinet: (j['onCabinet'] ?? false) as bool,
        problems: List<String>.from(j['problems'] as List), warnings: List<String>.from(j['warnings'] as List), version: (j['version'] ?? '') as String,
      );
}

class CabRun {
  CabRun({required this.id, required this.month, required this.by, required this.startedAt, required this.steps, required this.rows, this.approvedBy = '', this.approvedAt});

  final int id;
  final DateTime month, startedAt;
  final String by;
  final List<RunStep> steps;
  final List<RunRow> rows;
  String approvedBy;
  DateTime? approvedAt;

  Map<String, dynamic> toJson() => {
        'id': id, 'month': _st(month), 'by': by, 'startedAt': _st(startedAt), 'steps': steps.map((e) => e.toJson()).toList(),
        'rows': rows.map((e) => e.toJson()).toList(), 'approvedBy': approvedBy, 'approvedAt': _st(approvedAt),
      };

  factory CabRun.fromJson(Map<String, dynamic> j) => CabRun(
        id: j['id'] as int, month: _dt(j['month'])!, by: j['by'] as String, startedAt: _dt(j['startedAt'])!,
        steps: [for (final e in (j['steps'] as List)) RunStep.fromJson(Map<String, dynamic>.from(e as Map))],
        rows: [for (final e in (j['rows'] as List)) RunRow.fromJson(Map<String, dynamic>.from(e as Map))],
        approvedBy: (j['approvedBy'] ?? '') as String, approvedAt: _dt(j['approvedAt']),
      );
}

/// The state of a run row, recomputed from today's data (like the web's checklist): on the cabinet, submitted, data_missing,
/// validation_failed, ready_for_approval, approved, submitted_here.
enum RowState { onCabinet, dataMissing, validationFailed, ready, approved, submitted, awaiting }

// ───────────────────────── the data module ─────────────────────────

class CabinetData {
  CabinetData._(this.store, this.m, {this.live = false});

  final AppStore store;
  final Map<String, dynamic> m;

  /// Server mode: the real cabinet as the robot read it. Read-only: every change below throws `server_readonly`.
  final bool live;

  static final Expando<CabinetData> _liveCache = Expando<CabinetData>('cabinet-live');
  static final Expando<Object> _liveSeen = Expando<Object>('cabinet-live-json');

  /// The cabinet data of the company; it is created (seeded from the books, with dates relative to today) on first use.
  /// In server mode it is built from the server's answers instead (never seeded, never stored).
  static CabinetData of(AppStore s) {
    if (s.serverMode) {
      final raw = s.ext['server'];
      final cached = _liveCache[s];
      if (cached != null && identical(_liveSeen[s], raw)) return cached;
      final data = CabinetData._(s, cabinetFromServer(s, serverPart(s, 'cabinet'), serverPart(s, 'letters')), live: true);
      _liveCache[s] = data;
      _liveSeen[s] = raw;
      return data;
    }
    final cur = s.ext['cabinet'];
    if (cur is Map<String, dynamic>) return CabinetData._(s, cur);
    final data = CabinetData._(s, <String, dynamic>{});
    s.ext['cabinet'] = data.m;
    data._seed();
    return data;
  }

  // ───────── reads ─────────

  List<T> _list<T>(String key, T Function(Map<String, dynamic>) f) => [for (final e in (m[key] as List? ?? const [])) f(Map<String, dynamic>.from(e as Map))];

  void _put(String key, List<Map<String, dynamic>> v) => m[key] = v;

  Map<String, dynamic> get profile => Map<String, dynamic>.from((m['profile'] as Map?) ?? const {});
  List<TaxBalance> get balances => _list('balances', TaxBalance.fromJson);
  List<CabReport> get reports => _list('reports', CabReport.fromJson);
  List<CabOrder> get orders => _list('orders', CabOrder.fromJson)..sort((a, b) => b.date.compareTo(a.date));
  List<CabLetter> get letters => _list('letters', CabLetter.fromJson)..sort((a, b) => b.date.compareTo(a.date));
  List<CabPayment> get payments => _list('payments', CabPayment.fromJson)..sort((a, b) => b.id.compareTo(a.id));
  List<CabCheck> get checks => _list('checks', CabCheck.fromJson);
  List<CabRun> get runs => _list('runs', CabRun.fromJson)..sort((a, b) => b.id.compareTo(a.id));

  CabCheck? get lastCheck => checks.isEmpty ? null : checks.last;

  // server mode: was the server read, and may the person see the cabinet / the letters (a part is missing from the answer on a 403)
  bool get synced => !live || serverSynced(store);
  bool get canSeeCabinet => !live || ((m['access'] as Map?)?['cabinet'] ?? false) == true;
  bool get canSeeLetters => !live || ((m['access'] as Map?)?['letters'] ?? false) == true;

  /// When the robot last read the cabinet (server mode); null when it never did.
  DateTime? get readAt => _dt(m['readAt']);

  Map<String, dynamic> get _totals => Map<String, dynamic>.from((m['totals'] as Map?) ?? const {});

  int get totalDebt => live && _totals.isNotEmpty ? (_totals['debt'] as int? ?? 0) : balances.fold(0, (a, b) => a + b.debt);
  int get totalPenalty => live && _totals.isNotEmpty ? (_totals['penalty'] as int? ?? 0) : balances.fold(0, (a, b) => a + b.penalty);
  int get totalOverpaid => live && _totals.isNotEmpty ? (_totals['overpaid'] as int? ?? 0) : balances.fold(0, (a, b) => a + b.overpaid);

  void _ro() {
    if (live) throw AppError('server_readonly');
  }

  int paidThisYear([DateTime? now]) {
    if (live && _totals.isNotEmpty) return _totals['paidYear'] as int? ?? 0;
    final y = (now ?? DateTime.now()).year;
    return orders.where((o) => o.paid && o.date.year == y).fold(0, (a, o) => a + o.amount);
  }

  int get unansweredDemands => letters.where((l) => l.isDemand && !l.answered).length;
  int get unreadLetters => letters.where((l) => l.unread).length;

  // ───────── audit (every action has an actor) ─────────

  void _audit(String actor, String action, [String subject = '']) {
    store.audit.add(AuditEntry(at: DateTime.now(), actor: actor, action: action, subject: subject));
  }

  String get _me => store.user?.name ?? '';

  // ───────── seed ─────────

  void _seed() {
    final s = store;
    final now = _day(DateTime.now());
    final subm = s.reports.where((r) => r.status == ReportStatus.submitted).toList()..sort((a, b) => a.start.compareTo(b.start));
    final lastOf = <TaxType, TaxReport>{};
    for (final r in subm) {
      lastOf[r.type] = r;
    }

    final bal = <String, TaxBalance>{};
    final orders = <CabOrder>[];
    var seq = 0;
    for (final r in subm) {
      final isLast = identical(lastOf[r.type], r);
      final due = _dueOf(r);
      for (final e in _taxParts(r).entries) {
        if (e.value <= 0) continue;
        final b = bal.putIfAbsent(e.key, () => TaxBalance(tax: e.key));
        b.assessed += e.value;
        if (!isLast) {
          // earlier periods are paid; the latest filed period stays open on the personal account
          b.paid += e.value;
          orders.add(CabOrder(number: '${now.year}${(1000 + ++seq)}', date: due.subtract(const Duration(days: 2)), tax: e.key, kind: '08102', amount: e.value));
        }
      }
    }
    final wanted = s.company.regime == 'turnover' ? ['turnover', 'pit', 'social'] : [if (s.company.vatPayer) 'vat', 'profit', 'pit', 'social'];
    for (final t in wanted) {
      bal.putIfAbsent(t, () => TaxBalance(tax: t));
    }

    m['profile'] = {'tin': s.company.stir.replaceAll(RegExp(r'\D'), ''), 'name': s.company.name, 'vatPayer': s.company.vatPayer, 'regime': s.company.regime};
    m['balances'] = [for (final k in kCabTaxes) if (bal.containsKey(k)) bal[k]!.toJson()];
    m['reports'] = [
      for (final r in subm) CabReport(type: r.type.name, start: r.start, end: r.end, submittedAt: r.updatedAt, receipt: r.receipt).toJson(),
    ];
    m['orders'] = [for (final o in orders) o.toJson()];
    m['payments'] = <Map<String, dynamic>>[];
    m['checks'] = <Map<String, dynamic>>[];
    m['runs'] = <Map<String, dynamic>>[];
    m['letters'] = <Map<String, dynamic>>[];

    _seedLetters(now);
    // the first visit of the cabinet is done by the system, so the overview is not empty
    _runCheck('system', audit: false);
    // the assistant proposes to close the biggest debt (a proposal only: a person presses "Pay")
    final debts = balances.where((b) => b.debt > 0).toList()..sort((a, b) => b.debt.compareTo(a.debt));
    if (debts.isNotEmpty) {
      final p = CabPayment(id: s.nextId(), tax: debts.first.tax, kind: '08102', amount: debts.first.debt, proposedBy: 'ai');
      p.history.add(PayEvent(DateTime.now(), 'proposed', 'ai'));
      m['payments'] = [p.toJson()];
    }
  }

  DateTime _dueOf(TaxReport r) {
    try {
      final dl = store.book.deadline(r.type, r.end);
      final dm = DateTime(r.end.year, r.end.month + dl.monthsAfter, 1);
      return DateTime(dm.year, dm.month, dl.day);
    } on MissingTaxRule {
      return r.end.add(const Duration(days: 20));
    }
  }

  /// A report's tax split into the taxes of the personal account (payroll = PIT + social tax, from the approved payroll runs).
  Map<String, int> _taxParts(TaxReport r) {
    switch (r.type) {
      case TaxType.vat:
        return {'vat': r.taxMinor};
      case TaxType.profit:
        return {'profit': r.taxMinor};
      case TaxType.turnover:
        return {'turnover': r.taxMinor};
      case TaxType.payroll:
        final run = store.payrolls.where((p) => p.status != PayrollStatus.draft && p.period.year == r.start.year && p.period.month == r.start.month).firstOrNull;
        return run == null ? {'pit': r.taxMinor} : {'pit': run.pit, 'social': run.social};
    }
  }

  // ───────── letters ─────────

  void _seedLetters(DateTime now) {
    final debts = balances.where((b) => b.debt > 0).toList()..sort((a, b) => b.debt.compareTo(a.debt));
    final findings = _analyze(now).$1;
    final overdue = findings.where((f) => f.key == 'overdue' || f.key == 'missing').toList();
    final lastReport = reports.isEmpty ? null : (reports.toList()..sort((a, b) => b.end.compareTo(a.end))).first;
    final assessed = balances.fold(0, (a, b) => a + b.assessed);
    final paid = balances.fold(0, (a, b) => a + b.paid);

    String money(int v, String l) => formatMoney(v, translate(l, 'currency'));
    String date(DateTime d) => '${d.day.toString().padLeft(2, '0')}.${d.month.toString().padLeft(2, '0')}.${d.year}';
    String period(String l, DateTime s, DateTime e) {
      if (e.month - s.month == 2 && e.year == s.year) return translate(l, 'tax.period_quarter', {'year': s.year, 'q': (s.month - 1) ~/ 3 + 1});
      return '${translate(l, 'month.${s.month}')} ${s.year}';
    }

    var n = 0;
    CabLetter make({
      required String kind,
      required int daysAgo,
      int? deadlineIn,
      bool answered = false,
      bool unread = true,
      required String ask,
      int? amount,
      String? tax,
      String? reportType,
      DateTime? ps,
      DateTime? pe,
      required Map<String, String> Function(String l) title,
      required Map<String, String> Function(String l) body,
    }) {
      final d = now.subtract(Duration(days: daysAgo));
      return CabLetter(
        id: store.nextId(),
        kind: kind,
        number: '${now.year}${(40000 + ++n * 137 + daysAgo)}',
        date: d,
        deadline: deadlineIn == null ? null : now.add(Duration(days: deadlineIn)),
        answered: answered,
        unread: unread,
        ask: ask,
        amount: amount,
        tax: tax,
        reportType: reportType,
        periodStart: ps,
        periodEnd: pe,
        title: {for (final l in kLocales) l: title(l)['t']!},
        body: {for (final l in kLocales) l: body(l)['b']!},
      );
    }

    final out = <CabLetter>[];

    if (debts.isNotEmpty) {
      final b = debts.first;
      out.add(make(
        kind: 'requirement', daysAgo: 4, deadlineIn: 6, ask: 'pay_debt', amount: b.debt, tax: b.tax,
        title: (l) => {'t': _T.payDebtTitle[l]!},
        body: (l) => {'b': _fill(_T.payDebtBody[l]!, {'tax': translate(l, 'cab.tax.${b.tax}'), 'amount': money(b.debt, l), 'deadline': date(now.add(const Duration(days: 6)))})},
      ));
    }
    if (overdue.isNotEmpty) {
      final f = overdue.first;
      out.add(make(
        kind: 'requirement', daysAgo: 9, deadlineIn: f.key == 'overdue' ? -2 : 5, ask: 'file_report', reportType: f.type, ps: f.start, pe: f.end,
        title: (l) => {'t': _T.fileTitle[l]!},
        body: (l) => {
              'b': _fill(_T.fileBody[l]!, {
                'report': translate(l, 'taxtype.${f.type}'),
                'period': f.start == null ? '' : period(l, f.start!, f.end ?? f.start!),
                'deadline': date(f.due ?? now),
              }),
            },
      ));
    }
    if (lastReport != null) {
      out.add(make(
        kind: 'notice', daysAgo: 16, unread: false, ask: 'info', reportType: lastReport.type, ps: lastReport.start, pe: lastReport.end,
        title: (l) => {'t': _T.acceptedTitle[l]!},
        body: (l) => {
              'b': _fill(_T.acceptedBody[l]!, {
                'report': translate(l, 'taxtype.${lastReport.type}'),
                'period': period(l, lastReport.start, lastReport.end),
                'receipt': lastReport.receipt.isEmpty ? '—' : lastReport.receipt,
              }),
            },
      ));
    }
    out.add(make(
      kind: 'requirement', daysAgo: 38, deadlineIn: -24, answered: true, unread: false, ask: 'documents',
      title: (l) => {'t': _T.docsTitle[l]!},
      body: (l) => {'b': _T.docsBody[l]!},
    ));
    out.add(make(
      kind: 'notice', daysAgo: 22, unread: false, ask: 'info', amount: assessed,
      title: (l) => {'t': _T.accountTitle[l]!},
      body: (l) => {'b': _fill(_T.accountBody[l]!, {'assessed': money(assessed, l), 'paid': money(paid, l)})},
    ));
    out.add(make(
      kind: 'notice', daysAgo: 3, ask: 'info',
      title: (l) => {'t': _T.generalTitle[l]!},
      body: (l) => {'b': _T.generalBody[l]!},
    ));
    m['letters'] = [for (final e in out) e.toJson()];
  }

  static String _fill(String text, Map<String, String> vars) {
    var s = text;
    vars.forEach((k, v) => s = s.replaceAll('{$k}', v));
    return s;
  }

  /// Marks a letter as read (opening it is all that "read" means; the answer button of the cabinet is never pressed).
  void markRead(CabLetter l) {
    if (live || !l.unread) return; // the server's own "read" flag is the cabinet's: opening a letter here never changes it
    final all = letters;
    final i = all.indexWhere((e) => e.id == l.id);
    if (i < 0) return;
    all[i].unread = false;
    _put('letters', [for (final e in all) e.toJson()]);
    _audit('user', 'cab_letter_read', l.number);
    store.changed();
  }

  /// A deterministic explanation: only what the letter itself states (kind, amount, deadline, period) and the usual next steps.
  LetterInsight insight(CabLetter l, [DateTime? now]) {
    final today = _day(now ?? DateTime.now());
    return LetterInsight(
      ask: l.ask,
      amount: l.amount,
      tax: l.tax,
      deadline: l.deadline,
      daysLeft: l.deadline == null ? null : _day(l.deadline!).difference(today).inDays,
      reportType: l.reportType,
      periodStart: l.periodStart,
      periodEnd: l.periodEnd,
      steps: switch (l.ask) {
        'pay_debt' => ['pay', 'cabinet'],
        'file_report' => ['report', 'approval'],
        'documents' => ['documents'],
        _ => ['none'],
      },
    );
  }

  // ───────── analysis (port of App\Portal\PortalAnalyzer) ─────────

  /// Compares the cabinet snapshot with the books: wrong taxpayer, filed / missing / overdue / rejected reports, balances, unread letters.
  (List<CabFinding>, Map<String, int>) _analyze(DateTime now) {
    final s = store;
    final today = _day(now);
    final p = profile;
    final findings = <CabFinding>[];
    final sum = <String, int>{'submitted': 0, 'missing': 0, 'overdue': 0, 'rejected': 0};

    final tin = (p['tin'] ?? '') as String;
    final stir = s.company.stir.replaceAll(RegExp(r'\D'), '');
    if (tin.isNotEmpty && tin != stir) {
      return ([CabFinding(key: 'tin_mismatch', severity: Severity.error, portalValue: tin, tax: stir)], {'tin_mismatch': 1});
    }
    if (p['vatPayer'] != null && p['vatPayer'] != s.company.vatPayer) {
      findings.add(CabFinding(key: 'vat_status_mismatch', severity: Severity.warning, portalValue: (p['vatPayer'] as bool) ? 'yes' : 'no'));
    }
    if (p['regime'] != null && p['regime'] != s.company.regime) {
      findings.add(CabFinding(key: 'regime_mismatch', severity: Severity.warning, portalValue: p['regime'] as String));
    }

    // several filings of one period: the most final status wins
    const rank = {'accepted': 3, 'submitted': 2, 'rejected': 1, 'draft': 0};
    final onPortal = <String, CabReport>{};
    for (final r in reports) {
      final key = '${r.type}@${_day(r.start).toIso8601String()}';
      final cur = onPortal[key];
      if (cur == null || (rank[r.status] ?? 0) > (rank[cur.status] ?? 0)) onPortal[key] = r;
    }

    List<Obligation> calendar;
    try {
      calendar = s.calc.calendar(s.company, now);
    } on MissingTaxRule {
      calendar = const [];
    }
    for (final o in calendar) {
      if (!o.end.isBefore(today)) continue; // the period is still open
      final r = onPortal['${o.type.name}@${_day(o.start).toIso8601String()}'];
      final local = s.reportFor(o);
      CabFinding f(String key, Severity sev, {int? days, String? receipt}) => CabFinding(
            key: key, severity: sev, type: o.type.name, start: o.start, end: o.end, due: o.due, reportId: local?.id, days: days, receipt: receipt,
          );
      if (r != null && (r.status == 'accepted' || r.status == 'submitted')) {
        findings.add(f('submitted', Severity.ok, receipt: r.receipt));
        sum['submitted'] = sum['submitted']! + 1;
      } else if (r != null && r.status == 'rejected') {
        findings.add(f('rejected', Severity.error));
        sum['rejected'] = sum['rejected']! + 1;
      } else if (_day(o.due).isBefore(today)) {
        findings.add(f('overdue', Severity.error, days: today.difference(_day(o.due)).inDays));
        sum['overdue'] = sum['overdue']! + 1;
      } else {
        findings.add(f('missing', Severity.warning, days: _day(o.due).difference(today).inDays));
        sum['missing'] = sum['missing']! + 1;
      }
    }

    // reports we filed that the cabinet does not list
    for (final r in s.reports.where((r) => r.status == ReportStatus.submitted)) {
      if (!onPortal.containsKey('${r.type.name}@${_day(r.start).toIso8601String()}')) {
        findings.add(CabFinding(key: 'local_not_on_portal', severity: Severity.warning, type: r.type.name, start: r.start, end: r.end, reportId: r.id));
      }
    }

    for (final b in balances) {
      if (b.debt > 0) findings.add(CabFinding(key: 'debt', severity: Severity.warning, tax: b.tax, amount: b.debt));
      if (b.penalty > 0) findings.add(CabFinding(key: 'penalty', severity: Severity.warning, tax: b.tax, amount: b.penalty));
      if (b.overpaid > 0) findings.add(CabFinding(key: 'overpaid', severity: Severity.info, tax: b.tax, amount: b.overpaid));
    }
    final unread = unreadLetters;
    if (unread > 0) findings.add(CabFinding(key: 'unread_messages', severity: Severity.info, count: unread));

    const order = {Severity.error: 0, Severity.warning: 1, Severity.info: 2, Severity.ok: 3};
    findings.sort((a, b) {
      final c = order[a.severity]!.compareTo(order[b.severity]!);
      return c != 0 ? c : (a.due ?? DateTime(9999)).compareTo(b.due ?? DateTime(9999));
    });
    return (findings, sum);
  }

  /// "Check now": re-runs the comparison of the books with the (sandbox) cabinet snapshot. Nothing is read from the real cabinet.
  CabCheck _runCheck(String actor, {bool audit = true}) {
    final (findings, sum) = _analyze(DateTime.now());
    final c = CabCheck(
      at: DateTime.now(), by: actor == 'system' ? 'system' : _me, findings: findings,
      submitted: sum['submitted'] ?? 0, missing: sum['missing'] ?? 0, overdue: sum['overdue'] ?? 0, rejected: sum['rejected'] ?? 0,
    );
    final all = checks..add(c);
    _put('checks', [for (final e in (all.length > 8 ? all.sublist(all.length - 8) : all)) e.toJson()]);
    if (audit) _audit(actor, 'cab_check', '${c.findings.length}');
    return c;
  }

  CabCheck checkNow() {
    _ro();
    if (!store.role.canSeeTax) throw AppError('no_permission');
    final c = _runCheck('user');
    store.changed();
    return c;
  }

  // ───────── payments ─────────

  bool _validTax(String t) => kCabTaxes.contains(t);

  CabPayment _find(int id) {
    final p = payments.where((e) => e.id == id).firstOrNull;
    if (p == null) throw AppError('cab_pay_gone');
    return p;
  }

  void _savePayment(CabPayment p) {
    final all = payments;
    final i = all.indexWhere((e) => e.id == p.id);
    if (i < 0) {
      all.add(p);
    } else {
      all[i] = p;
    }
    _put('payments', [for (final e in all) e.toJson()]);
  }

  /// Prepares a payment. It is never paid here: a person must press "Pay" and confirm tax, type and amount.
  CabPayment propose({required String tax, required String kind, required int amount, String purpose = '', String actor = 'user'}) {
    _ro();
    if (!_validTax(tax) || !kPayKinds.contains(kind)) throw AppError('cab_unknown_type');
    if (amount < kTiyin || amount > kMaxPayMinor) throw AppError('cab_amount');
    for (final p in payments) {
      if (p.tax == tax && p.amount == amount && p.kind == kind) {
        if (p.open) throw AppError('cab_in_progress');
        if (p.status == PayStatus.unknown) throw AppError('cab_unsettled');
      }
    }
    final p = CabPayment(id: store.nextId(), tax: tax, kind: kind, amount: amount, purpose: purpose, proposedBy: actor);
    p.history.add(PayEvent(DateTime.now(), 'proposed', actor));
    _savePayment(p);
    _audit(actor, 'cab_pay_proposed', '$tax ${amount ~/ kTiyin}');
    store.changed();
    return p;
  }

  void _needApprover() {
    if (!store.role.canApprove) throw AppError('no_permission');
  }

  /// "Check without paying": the form is validated and nothing is paid.
  void checkPayment(CabPayment p) {
    _ro();
    _needApprover();
    final cur = _find(p.id);
    if (!cur.open) throw AppError('cab_pay_state');
    cur.status = PayStatus.checked;
    cur.history.add(PayEvent(DateTime.now(), 'checked', 'user'));
    _savePayment(cur);
    _audit('user', 'cab_pay_checked', '${cur.tax} ${cur.amount ~/ kTiyin}');
    store.changed();
  }

  /// The person's approval: it must repeat exactly the tax, the type and the amount of the proposal. Sandbox: only a test receipt is recorded.
  void pay(CabPayment p, {required String tax, required String kind, required int amount}) {
    _ro();
    _needApprover();
    final cur = _find(p.id);
    if (!cur.open) throw AppError('cab_pay_state');
    if ('$tax|$kind|$amount' != cur.binding) throw AppError('cab_changed');
    final number = 'SBX-${DateTime.now().year}-${cur.id}';
    cur.status = PayStatus.paid;
    cur.approvedBy = _me;
    cur.approvedAt = DateTime.now();
    cur.orderNumber = number;
    cur.history.add(PayEvent(DateTime.now(), 'paid', 'user'));
    _savePayment(cur);
    _applyToCabinet(cur, number);
    _audit('user', 'cab_pay_paid', '${cur.tax} ${cur.amount ~/ kTiyin}');
    store.changed();
  }

  void _applyToCabinet(CabPayment p, String number) {
    final bal = balances;
    var b = bal.where((e) => e.tax == p.tax).firstOrNull;
    if (b == null) {
      b = TaxBalance(tax: p.tax);
      bal.add(b);
    }
    if (p.kind == '08108') {
      b.penalty = b.penalty > p.amount ? b.penalty - p.amount : 0;
    } else {
      b.paid += p.amount; // prepayment or payment by a report: the debt shrinks, or an overpayment appears
    }
    _put('balances', [for (final e in bal) e.toJson()]);
    final ord = orders..add(CabOrder(number: number, date: _day(DateTime.now()), tax: p.tax, kind: p.kind, amount: p.amount));
    _put('orders', [for (final e in ord) e.toJson()]);
  }

  void cancelPayment(CabPayment p) {
    _ro();
    _needApprover();
    final cur = _find(p.id);
    if (!cur.open) throw AppError('cab_not_cancellable');
    cur.status = PayStatus.cancelled;
    cur.history.add(PayEvent(DateTime.now(), 'cancelled', 'user'));
    _savePayment(cur);
    _audit('user', 'cab_pay_cancelled', '${cur.tax} ${cur.amount ~/ kTiyin}');
    store.changed();
  }

  /// A payment whose outcome is unknown is never paid again: the cabinet's order list decides.
  void settleUnknown(CabPayment p) {
    _ro();
    _needApprover();
    final cur = _find(p.id);
    if (cur.status != PayStatus.unknown) throw AppError('cab_pay_state');
    final found = orders.any((o) => o.paid && o.tax == cur.tax && o.kind == cur.kind && o.amount == cur.amount && !o.date.isBefore(_day(cur.approvedAt ?? cur.history.first.at)));
    cur.status = found ? PayStatus.paid : PayStatus.failed;
    cur.history.add(PayEvent(DateTime.now(), cur.status.name, 'system'));
    _savePayment(cur);
    _audit('system', 'cab_pay_settled', cur.status.name);
    store.changed();
  }

  /// "Pay everything due": per tax its debt (or, with no debt, its penalty).
  List<PlanItem> plan() {
    final items = <PlanItem>[];
    for (final b in balances) {
      if (b.debt > 0) {
        items.add(PlanItem(b.tax, '08102', b.debt));
      } else if (b.penalty > 0) {
        items.add(PlanItem(b.tax, '08108', b.penalty));
      }
    }
    return items.where((i) => i.amount >= kTiyin && i.amount <= kMaxPayMinor).toList();
  }

  /// What the person saw when pressing the button: a list that changed since is not paid.
  String planHash(List<PlanItem> items) => items.map((e) => '${e.tax}|${e.kind}|${e.amount}').join(';');

  int payAll(String hash) {
    _ro();
    _needApprover();
    final items = plan();
    if (items.isEmpty || planHash(items) != hash) throw AppError('cab_plan_changed');
    var done = 0;
    for (final i in items) {
      // an open proposal of the same tax and amount is reused (never two payments for one debt)
      final existing = payments.where((p) => p.open && p.tax == i.tax && p.kind == i.kind && p.amount == i.amount).firstOrNull;
      final p = existing ?? propose(tax: i.tax, kind: i.kind, amount: i.amount);
      pay(p, tax: i.tax, kind: i.kind, amount: i.amount);
      done++;
    }
    return done;
  }

  // ───────── automatic preparation ─────────

  /// The reports whose period contains [month] (monthly and quarterly), by the company's regime and the deadline rules.
  List<Obligation> obligationsForMonth(DateTime month) {
    final c = store.company;
    final types = <TaxType>[if (c.regime == 'turnover') TaxType.turnover else ...[if (c.vatPayer) TaxType.vat, TaxType.profit], TaxType.payroll];
    final out = <Obligation>[];
    for (final t in types) {
      final dl = store.book.deadline(t, month);
      late DateTime start, end;
      if (dl.quarterly) {
        final qi = (month.month - 1) ~/ 3;
        start = DateTime(month.year, qi * 3 + 1, 1);
        end = DateTime(month.year, qi * 3 + 4, 0);
      } else {
        start = DateTime(month.year, month.month, 1);
        end = DateTime(month.year, month.month + 1, 0);
      }
      final dm = DateTime(end.year, end.month + dl.monthsAfter, 1);
      out.add(Obligation(t, start, end, DateTime(dm.year, dm.month, dl.day)));
    }
    return out;
  }

  List<TaxType> notRequiredFor(DateTime month) {
    final req = obligationsForMonth(month).map((o) => o.type).toSet();
    return [for (final t in TaxType.values) if (!req.contains(t)) t];
  }

  /// Runs the analysis of a month: profile → cabinet → obligations → data → prepare → validate. Reports are only PREPARED, never submitted.
  CabRun startRun(DateTime month) {
    _ro();
    if (!store.role.canSeeTax) throw AppError('no_permission');
    final s = store;
    final mo = DateTime(month.year, month.month, 1);
    final next = DateTime(mo.year, mo.month + 1, 1);
    final steps = <RunStep>[];

    steps.add(RunStep('profile', 'done', {'name': s.company.name, 'tin': s.company.stir, 'regime': s.company.regime}));

    final check = _runCheck('system', audit: false);
    steps.add(RunStep('portal', 'done', {'submitted': '${check.submitted}', 'missing': '${check.missing + check.overdue}'}));

    final obligations = obligationsForMonth(mo);
    final notReq = notRequiredFor(mo);
    steps.add(RunStep('obligations', 'done', {'required': '${obligations.length}', 'not_required': '${notReq.length}'}));

    final docs = s.docs.where((d) => !d.issueDate.isBefore(mo) && d.issueDate.isBefore(next)).length;
    final tx = s.txns.where((t) => !t.occurredAt.isBefore(mo) && t.occurredAt.isBefore(next)).length;
    var empty = 0;
    for (final o in obligations) {
      final hasDocs = s.docs.any((d) => !d.issueDate.isBefore(o.start) && !d.issueDate.isAfter(o.end));
      final hasTx = s.txns.any((t) => !t.occurredAt.isBefore(o.start) && t.occurredAt.isBefore(o.end.add(const Duration(days: 1))));
      final hasPay = s.payrolls.any((p) => !p.period.isBefore(o.start) && !p.period.isAfter(o.end));
      if (!hasDocs && !hasTx && !hasPay) empty++;
    }
    steps.add(RunStep('data', 'done', {'documents': '$docs', 'transactions': '$tx', 'empty_periods': '$empty'}));

    final onCab = <String, CabReport>{for (final r in reports) '${r.type}@${_day(r.start).toIso8601String()}': r};
    final rows = <RunRow>[];
    var prepared = 0, skipped = 0, failed = 0, ready = 0, problemsN = 0;
    final unclassified = s.txns.where((t) => t.categoryId == null).length;
    final hasTin = s.company.stir.replaceAll(RegExp(r'\D'), '').length == 9;
    for (final o in obligations) {
      final cr = onCab['${o.type.name}@${_day(o.start).toIso8601String()}'];
      final filed = cr != null && (cr.status == 'accepted' || cr.status == 'submitted');
      if (filed) {
        skipped++;
        rows.add(RunRow(type: o.type, start: o.start, end: o.end, due: o.due, reportId: s.reportFor(o)?.id, onCabinet: true));
        continue;
      }
      final problems = <String>[];
      final warnings = <String>[];
      if (!hasTin) problems.add('no_tin');
      TaxReport? rep;
      try {
        final existing = s.reportFor(o);
        if (existing != null && existing.status != ReportStatus.prepared) {
          rep = existing; // already waiting for / holding an approval: left as it is
          skipped++;
        } else {
          final comp = s.compute(o.type, o.start, o.end);
          final hasData = s.docs.any((d) => !d.issueDate.isBefore(o.start) && !d.issueDate.isAfter(o.end)) ||
              s.txns.any((t) => !t.occurredAt.isBefore(o.start) && t.occurredAt.isBefore(o.end.add(const Duration(days: 1)))) ||
              s.payrolls.any((p) => !p.period.isBefore(o.start) && !p.period.isAfter(o.end));
          if (!hasData && comp.baseMinor == 0 && comp.taxMinor == 0) {
            problems.add('no_books'); // never an invented "0 so'm"
          } else {
            rep = s.prepareReport(o);
            prepared++;
          }
        }
      } on MissingTaxRule {
        failed++;
        problems.add('rules_missing');
      }
      if (rep != null && !rep.rulesVerified) warnings.add('unverified_rules');
      if (_day(o.due).isBefore(_day(DateTime.now()))) warnings.add('overdue');
      if (unclassified > 0) warnings.add('unclassified');
      if (problems.isEmpty) {
        ready++;
      } else {
        problemsN++;
      }
      rows.add(RunRow(
        type: o.type, start: o.start, end: o.end, due: o.due, reportId: rep?.id, problems: problems, warnings: warnings,
        version: rep == null ? '' : _version(rep),
      ));
    }
    steps.add(RunStep('prepare', 'done', {'prepared': '$prepared', 'skipped': '$skipped', 'failed': '$failed'}));
    steps.add(RunStep('validate', 'done', {'ready': '$ready', 'problems': '$problemsN'}));

    final run = CabRun(id: s.nextId(), month: mo, by: _me, startedAt: DateTime.now(), steps: steps, rows: rows);
    final all = runs..add(run);
    _put('runs', [for (final e in (all.length > 6 ? all.sublist(all.length - 6) : all)) e.toJson()]);
    _audit('user', 'cab_run_started', '${mo.month}.${mo.year}');
    store.changed();
    return run;
  }

  /// The version of a report that was reviewed: a change afterwards invalidates the approval.
  String _version(TaxReport r) => '${r.id}:${r.baseMinor}:${r.taxMinor}:${r.updatedAt.millisecondsSinceEpoch}';

  TaxReport? reportOf(RunRow r) => r.reportId == null ? null : store.reports.where((e) => e.id == r.reportId).firstOrNull;

  /// The row's state from today's data (the checklist of the web page).
  RowState stateOf(RunRow r) {
    if (r.onCabinet) return RowState.onCabinet;
    final rep = reportOf(r);
    if (rep != null) {
      if (rep.status == ReportStatus.submitted) return RowState.submitted;
      if (rep.status == ReportStatus.approved) return RowState.approved;
      if (rep.status == ReportStatus.awaiting) return RowState.awaiting;
    }
    if (r.problems.contains('no_books')) return RowState.dataMissing;
    if (r.problems.isNotEmpty || rep == null) return RowState.validationFailed;
    return RowState.ready;
  }

  /// Only a report that is still the reviewed version can be selected for approval.
  bool selectable(RunRow r) {
    if (stateOf(r) != RowState.ready) return false;
    final rep = reportOf(r);
    return rep != null && _version(rep) == r.version;
  }

  int payable(RunRow r) => reportOf(r)?.taxMinor ?? 0;

  /// "Approve and submit the selected reports": each one goes through request → approve → sandbox submit, only for a person with the right,
  /// only with the declaration, and only if it is still the reviewed version. Returns how many were submitted and the refusals.
  ({int approved, List<String> refused}) approveRun(CabRun run, List<RunRow> selected, {required bool declaration}) {
    _ro();
    if (!store.role.canApprove) throw AppError('no_permission');
    if (!declaration) throw AppError('cab_declaration');
    if (selected.isEmpty) throw AppError('cab_nothing_selected');
    var ok = 0;
    final refused = <String>[];
    for (final row in selected) {
      final rep = reportOf(row);
      if (rep == null || _version(rep) != row.version || !selectable(row)) {
        refused.add('cab_version_changed');
        continue;
      }
      try {
        if (rep.status == ReportStatus.prepared) store.requestApproval(rep);
        store.approveReport(rep);
        store.submitReport(rep);
        ok++;
      } on AppError catch (e) {
        refused.add(e.key);
      }
    }
    if (ok > 0) {
      final all = runs;
      final i = all.indexWhere((e) => e.id == run.id);
      if (i >= 0) {
        all[i].approvedBy = _me;
        all[i].approvedAt = DateTime.now();
        _put('runs', [for (final e in all) e.toJson()]);
      }
      _audit('user', 'cab_run_approved', '$ok');
    }
    store.changed();
    return (approved: ok, refused: refused);
  }
}

// ───────────────────────── seed letter texts (the cabinet's own wording, per language) ─────────────────────────

class _T {
  static const payDebtTitle = {
    'uz': 'Soliq qarzdorligini toʻlash toʻgʻrisida talabnoma',
    'ru': 'Требование об уплате налоговой задолженности',
    'tg': 'Талабнома оид ба пардохти қарздории андоз',
  };
  static const payDebtBody = {
    'uz': 'Hurmatli soliq toʻlovchi! Shaxsiy hisobvaraqingizda {tax} boʻyicha {amount} miqdorida qarzdorlik mavjud. Qarzni {deadline} gacha toʻlashingiz yoki toʻlov imkoniyati boʻlmasa, sababini yozma bildirishingiz talab qilinadi. Muddatida bajarilmasa, qonunchilikda belgilangan choralar koʻriladi.',
    'ru': 'Уважаемый налогоплательщик! На вашем лицевом счёте числится задолженность по налогу «{tax}» в размере {amount}. Просим погасить долг до {deadline} либо письменно сообщить причину, если оплата невозможна. При неисполнении в срок будут применены меры, предусмотренные законодательством.',
    'tg': 'Пардохткунандаи муҳтарами андоз! Дар суратҳисоби шахсии шумо аз рӯи андози «{tax}» қарздорӣ ба маблағи {amount} мавҷуд аст. Аз шумо талаб мешавад, ки қарзро то {deadline} пардохт кунед ё агар пардохт номумкин бошад, сабабро хаттӣ хабар диҳед. Дар сурати иҷро нашудан дар мӯҳлат, тадбирҳои қонунгузорӣ андешида мешавад.',
  };
  static const fileTitle = {
    'uz': 'Hisobotni topshirish toʻgʻrisida talabnoma',
    'ru': 'Требование о представлении отчёта',
    'tg': 'Талабнома оид ба пешниҳоди ҳисобот',
  };
  static const fileBody = {
    'uz': 'Hurmatli soliq toʻlovchi! {report} boʻyicha {period} davri uchun hisobot kabinetda topshirilmagan. Hisobotni {deadline} gacha topshirishingiz soʻraladi.',
    'ru': 'Уважаемый налогоплательщик! Отчёт «{report}» за период {period} в кабинете не представлен. Просим представить отчёт до {deadline}.',
    'tg': 'Пардохткунандаи муҳтарами андоз! Ҳисоботи «{report}» барои давраи {period} дар кабинет пешниҳод нашудааст. Аз шумо хоҳиш мешавад, ки ҳисоботро то {deadline} пешниҳод кунед.',
  };
  static const acceptedTitle = {
    'uz': 'Hisobot qabul qilingani haqida xabarnoma',
    'ru': 'Уведомление о принятии отчёта',
    'tg': 'Огоҳинома оид ба қабули ҳисобот',
  };
  static const acceptedBody = {
    'uz': '{report} hisoboti ({period}) qabul qilindi. Qabul raqami: {receipt}.',
    'ru': 'Отчёт «{report}» за {period} принят. Номер приёма: {receipt}.',
    'tg': 'Ҳисоботи «{report}» барои {period} қабул шуд. Рақами қабул: {receipt}.',
  };
  static const docsTitle = {
    'uz': 'Hujjatlarni taqdim etish toʻgʻrisida talabnoma',
    'ru': 'Требование о представлении документов',
    'tg': 'Талабнома оид ба пешниҳоди ҳуҷҷатҳо',
  };
  static const docsBody = {
    'uz': 'Kameral nazorat doirasida birlamchi hujjatlarni (hisob-fakturalar, dalolatnomalar, bank koʻchirmalari) taqdim etish soʻraladi. Hujjatlar taqdim etilgan, javob berilgan.',
    'ru': 'В рамках камерального контроля просим представить первичные документы (счета-фактуры, акты, банковские выписки). Документы представлены, ответ дан.',
    'tg': 'Дар доираи назорати камералӣ пешниҳоди ҳуҷҷатҳои аввалия (ҳисоб-фактураҳо, далолатномаҳо, хуруҷҳои бонк) хоҳиш карда мешавад. Ҳуҷҷатҳо пешниҳод шуда, ҷавоб дода шудааст.',
  };
  static const accountTitle = {
    'uz': 'Shaxsiy hisobvaraq holati haqida xabarnoma',
    'ru': 'Уведомление о состоянии лицевого счёта',
    'tg': 'Огоҳинома оид ба ҳолати суратҳисоби шахсӣ',
  };
  static const accountBody = {
    'uz': 'Shaxsiy hisobvaraqdagi holat: jami hisoblangan {assessed}, toʻlangan {paid}.',
    'ru': 'Состояние лицевого счёта: всего начислено {assessed}, оплачено {paid}.',
    'tg': 'Ҳолати суратҳисоби шахсӣ: ҳамагӣ ҳисоб шудааст {assessed}, пардохт шудааст {paid}.',
  };
  static const generalTitle = {
    'uz': 'Hisobotlar va toʻlov muddatlari haqida xabarnoma',
    'ru': 'Уведомление о сроках отчётности и оплаты',
    'tg': 'Огоҳинома оид ба мӯҳлатҳои ҳисобот ва пардохт',
  };
  static const generalBody = {
    'uz': 'Soliq toʻlovchilarga: hisobotlarni topshirish va toʻlovlarni amalga oshirish muddatlari Soliq kodeksida belgilangan. Muddatlarni soliq kalendaridan kuzatib boring.',
    'ru': 'Налогоплательщикам: сроки представления отчётности и уплаты налогов установлены Налоговым кодексом. Следите за сроками в налоговом календаре.',
    'tg': 'Ба пардохткунандагони андоз: мӯҳлатҳои пешниҳоди ҳисобот ва пардохти андозҳо дар Кодекси андоз муқаррар шудаанд. Мӯҳлатҳоро аз тақвими андоз пайгирӣ кунед.',
  };
}
