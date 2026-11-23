import '../core/money.dart';
import '../l10n/strings.dart';
import 'models.dart';
import 'server_modules.dart';
import 'store.dart';

/// Advances on pay, KPI bonuses and the ERI key list. Kept in `store.ext['extras']` (saved with the books).
/// In SERVER mode they are the real company's: built from the server's answers (never stored, never seeded) and read-only here.
/// Advances and KPI work exactly like the web platform: an advance is booked as an expense when it is given and is deducted from the
/// month's net pay; a KPI bonus is added to the gross pay, so PIT/INPS/social tax are counted on it by the same rules table.

DateTime monthStart(DateTime d) => DateTime(d.year, d.month, 1);

/// A KPI goal may be exceeded, but not without a limit: up to 200 % (20 000 basis points).
const int kKpiMaxBp = 20000;

DateTime? _dt(dynamic v) => v == null ? null : DateTime.parse(v as String);

/// Money given to a person on account of the month's pay.
class Advance {
  Advance({required this.id, required this.employeeId, required this.period, required this.givenOn, required this.amountMinor, required this.accountId, this.txnId, this.note = '', this.employeeName = ''});

  final int id;
  String employeeName; // server mode: the name the platform kept, for a person the phone has no employee card of
  int employeeId;
  DateTime period; // first day of the month the advance counts for
  DateTime givenOn;
  int amountMinor, accountId;
  int? txnId; // the expense entry written when it was given
  String note;

  Map<String, dynamic> toJson() => {
        'id': id, 'employeeId': employeeId, 'period': period.toIso8601String(), 'givenOn': givenOn.toIso8601String(),
        'amountMinor': amountMinor, 'accountId': accountId, 'txnId': txnId, 'note': note, 'employeeName': employeeName,
      };

  factory Advance.fromJson(Map<String, dynamic> j) => Advance(
        id: j['id'] as int, employeeId: j['employeeId'] as int, period: _dt(j['period'])!, givenOn: _dt(j['givenOn'])!,
        amountMinor: j['amountMinor'] as int, accountId: j['accountId'] as int, txnId: j['txnId'] as int?, note: (j['note'] ?? '') as String,
        employeeName: (j['employeeName'] ?? '') as String,
      );
}

/// A KPI bonus: the bonus at 100 %, and how much of the goal was reached (basis points). The amount is counted, never typed in.
class KpiBonus {
  KpiBonus({required this.id, required this.employeeId, required this.period, required this.title, required this.maxBonusMinor, required this.achievementBp, this.note = '', this.employeeName = ''});

  final int id;
  String employeeName;
  int employeeId;
  DateTime period;
  String title, note;
  int maxBonusMinor, achievementBp;

  int get amountMinor => kpiAmount(maxBonusMinor, achievementBp);

  Map<String, dynamic> toJson() => {
        'id': id, 'employeeId': employeeId, 'period': period.toIso8601String(), 'title': title, 'maxBonusMinor': maxBonusMinor,
        'achievementBp': achievementBp, 'note': note, 'employeeName': employeeName,
      };

  factory KpiBonus.fromJson(Map<String, dynamic> j) => KpiBonus(
        id: j['id'] as int, employeeId: j['employeeId'] as int, period: _dt(j['period'])!, title: j['title'] as String,
        maxBonusMinor: j['maxBonusMinor'] as int, achievementBp: j['achievementBp'] as int, note: (j['note'] ?? '') as String,
        employeeName: (j['employeeName'] ?? '') as String,
      );
}

/// Bonus = the most that can be earned x the part of the goal reached, in whole tiyin.
int kpiAmount(int maxMinor, int achievementBp) => maxMinor * achievementBp.clamp(0, kKpiMaxBp) ~/ 10000;

/// "85", "85,5" or "85.25 %" to basis points; null when it is not a number between 0 and 200.
int? percentToBp(String input) {
  final clean = input.replaceAll(RegExp(r'[\s%]'), '').replaceAll(',', '.');
  final m = RegExp(r'^(\d{1,3})(?:\.(\d{1,2}))?$').firstMatch(clean);
  if (m == null) return null;
  final bp = int.parse(m.group(1)!) * 100 + int.parse((m.group(2) ?? '').padRight(2, '0'));
  return bp <= kKpiMaxBp ? bp : null;
}

String percentLabel(int bp) {
  if (bp % 100 == 0) return '${bp ~/ 100}%';
  final s = (bp / 100).toStringAsFixed(2).replaceFirst(RegExp(r'0+$'), '').replaceFirst('.', ',');
  return '$s%';
}

enum EriState { valid, expiring30, expiring14, expiring3, expired, revoked }

/// An ERI/E-IMZO key as the server vault lists it. Only the description is here: the key file and its password are never on the phone.
class EriKey {
  EriKey({required this.id, required this.label, required this.ownerName, required this.ownerTin, required this.serial, required this.validFrom, required this.validTo, this.revoked = false});

  final int id;
  final String label, ownerName, ownerTin, serial;
  final DateTime validFrom, validTo;
  final bool revoked;

  int daysLeft(DateTime now) => DateTime(validTo.year, validTo.month, validTo.day).difference(DateTime(now.year, now.month, now.day)).inDays;

  EriState state(DateTime now) {
    if (revoked) return EriState.revoked;
    final d = daysLeft(now);
    if (d < 0) return EriState.expired;
    if (d <= 3) return EriState.expiring3;
    if (d <= 14) return EriState.expiring14;
    if (d <= 30) return EriState.expiring30;
    return EriState.valid;
  }

  Map<String, dynamic> toJson() => {
        'id': id, 'label': label, 'ownerName': ownerName, 'ownerTin': ownerTin, 'serial': serial,
        'validFrom': validFrom.toIso8601String(), 'validTo': validTo.toIso8601String(), 'revoked': revoked,
      };

  factory EriKey.fromJson(Map<String, dynamic> j) => EriKey(
        id: j['id'] as int, label: (j['label'] ?? '') as String, ownerName: (j['ownerName'] ?? '') as String, ownerTin: (j['ownerTin'] ?? '') as String,
        serial: (j['serial'] ?? '') as String, validFrom: _dt(j['validFrom'])!, validTo: _dt(j['validTo'])!, revoked: (j['revoked'] ?? false) as bool,
      );
}

/// Demo content of the three modules (called when the demo books are (re)built).
void seedExtras(AppStore s, {DateTime? today}) {
  final now = today ?? DateTime.now();
  final month = monthStart(now);
  final adv = <Map<String, dynamic>>[];
  final kpi = <Map<String, dynamic>>[];

  final sales = s.employees.where((e) => e.position.toLowerCase().contains('sotuv')).toList();
  final account = s.accounts.isEmpty ? null : s.accounts.first;
  if (sales.length >= 2 && account != null) {
    final givenOn = now.day >= 4 ? DateTime(now.year, now.month, now.day - 3) : month;
    for (final e in sales.take(2)) {
      final id = s.nextId();
      final txn = Txn(
        id: s.nextId(),
        accountId: account.id,
        type: TxType.expense,
        amountMinor: 200000000,
        occurredAt: givenOn.add(const Duration(hours: 11)),
        categoryId: s.categoryByKey('salary')?.id,
        description: translate(s.locale, 'adv.txn_description', {'name': e.fullName}),
      );
      s.txns.add(txn);
      adv.add(Advance(id: id, employeeId: e.id, period: month, givenOn: givenOn, amountMinor: 200000000, accountId: account.id, txnId: txn.id).toJson());
    }
    kpi
      ..add(KpiBonus(id: s.nextId(), employeeId: sales[0].id, period: month, title: translate(s.locale, 'kpi.demo_goal'), maxBonusMinor: 300000000, achievementBp: 8500).toJson())
      ..add(KpiBonus(id: s.nextId(), employeeId: sales[1].id, period: month, title: translate(s.locale, 'kpi.demo_goal'), maxBonusMinor: 300000000, achievementBp: 10000).toJson());
  }
  s.ext['extras'] = <String, dynamic>{'advances': adv, 'kpi': kpi, 'keys': _demoKeys(s, now)};
}

List<Map<String, dynamic>> _demoKeys(AppStore s, DateTime now) {
  final d = DateTime(now.year, now.month, now.day);
  return [
    EriKey(id: s.nextId(), label: 'ERI — direktor', ownerName: s.company.director.isEmpty ? 'Director' : s.company.director, ownerTin: s.company.stir.replaceAll(' ', ''), serial: '5A3F9C21B07E4D18', validFrom: d.subtract(const Duration(days: 330)), validTo: d.add(const Duration(days: 400))).toJson(),
    EriKey(id: s.nextId(), label: 'ERI — bosh buxgalter', ownerName: 'Karimova Dilnoza', ownerTin: '31204987650012', serial: '77B0E5D9C3A1F624', validFrom: d.subtract(const Duration(days: 353)), validTo: d.add(const Duration(days: 12))).toJson(),
  ];
}

final Expando<Map<String, dynamic>> _liveExtras = Expando<Map<String, dynamic>>('extras-live');
final Expando<Object> _liveExtrasSeen = Expando<Object>('extras-live-json');

extension ExtrasStore on AppStore {
  /// Server mode: the real advances, bonuses and keys from the server's answers (rebuilt when the phone reads the server again).
  Map<String, dynamic> get _live {
    final raw = ext['server'];
    final cached = _liveExtras[this];
    if (cached != null && identical(_liveExtrasSeen[this], raw)) return cached;
    final built = extrasFromServer(this);
    _liveExtras[this] = built;
    _liveExtrasSeen[this] = raw;
    return built;
  }

  /// Server mode: was the server read, and may this person see [part] (`advances`, `kpi` or `keys`)? Always true in demo mode.
  bool extrasSynced() => !serverMode || serverSynced(this);
  bool extrasAllowed(String part) => !serverMode || ((_live['access'] as Map?)?[part] ?? false) == true;

  Map<String, dynamic> get _x {
    if (serverMode) return _live;
    final cur = ext['extras'];
    if (cur is Map) return cur.cast<String, dynamic>();
    // an older installation: start empty, with the demo key list
    final fresh = <String, dynamic>{'advances': <dynamic>[], 'kpi': <dynamic>[], 'keys': _demoKeys(this, DateTime.now())};
    ext['extras'] = fresh;
    return fresh;
  }

  List<T> _list<T>(String key, T Function(Map<String, dynamic>) f) =>
      ((_x[key] as List?) ?? const []).map((e) => f(Map<String, dynamic>.from(e as Map))).toList();

  void _put(String key, List<Map<String, dynamic>> items) => _x[key] = items;

  // ───────────────────────── reads ─────────────────────────

  List<Advance> get advances => _list('advances', Advance.fromJson);
  List<KpiBonus> get kpiBonuses => _list('kpi', KpiBonus.fromJson);
  List<EriKey> get eriKeys => _list('keys', EriKey.fromJson);

  List<Advance> advancesOf(DateTime month) {
    final m = monthStart(month);
    return advances.where((a) => a.period == m).toList()..sort((a, b) => b.givenOn.compareTo(a.givenOn));
  }

  List<KpiBonus> kpiOf(DateTime month) {
    final m = monthStart(month);
    return kpiBonuses.where((b) => b.period == m).toList();
  }

  /// What each person was given in the month, by employee id.
  Map<int, int> advanceTotals(DateTime month) {
    final out = <int, int>{};
    for (final a in advancesOf(month)) {
      out[a.employeeId] = (out[a.employeeId] ?? 0) + a.amountMinor;
    }
    return out;
  }

  /// The KPI bonuses of the month, by employee id.
  Map<int, int> kpiTotals(DateTime month) {
    final out = <int, int>{};
    for (final b in kpiOf(month)) {
      out[b.employeeId] = (out[b.employeeId] ?? 0) + b.amountMinor;
    }
    return out;
  }

  /// Who may change advances and bonuses (the web's `can.write:employees.edit`).
  bool get canEditPeople => !serverMode && (role.canApprove || role.canManagePeople);

  /// A month whose payroll is approved or paid is closed: advances and bonuses are not changed any more.
  bool monthClosed(DateTime month) {
    final run = payrollFor(month);
    return run != null && run.status != PayrollStatus.draft;
  }

  /// The net pay of a gross amount by the same rules table as a payroll line.
  int netOfGross(int gross, DateTime month) {
    final l = PayrollLine(employeeId: null, name: '', gross: gross, pit: 0, inps: 0, social: 0);
    return calc.recalcLine(l, monthStart(month), company.budgetOrg).net;
  }

  /// What the person's net pay is expected to be: the draft line if there is one (it may carry a hand-made change), else salary + bonuses less tax.
  int estimatedNet(Employee e, DateTime month) {
    final run = payrollFor(month);
    final line = run?.lines.where((l) => l.employeeId == e.id).firstOrNull;
    if (line != null) return line.net;
    return netOfGross(e.salaryMinor + (kpiTotals(month)[e.id] ?? 0), month);
  }

  /// The most that can still be given to this person in the month.
  int advanceAllowance(Employee e, DateTime month) => estimatedNet(e, month) - (advanceTotals(month)[e.id] ?? 0);

  // ───────────────────────── advances ─────────────────────────

  void _guard(DateTime month) {
    if (serverMode) throw AppError('server_readonly');
    if (!canEditPeople) throw AppError('no_permission');
    if (monthClosed(month)) throw AppError('adv_closed');
  }

  String _money(int minor) => formatMoney(minor, translate(locale, 'currency'));

  Advance giveAdvance({required Employee employee, required DateTime month, required DateTime givenOn, required int amountMinor, required int accountId, String note = ''}) {
    final m = monthStart(month);
    _guard(m);
    if (amountMinor <= 0) throw AppError('amount');
    if (account(accountId) == null) throw AppError('no_account');
    final left = advanceAllowance(employee, m);
    if (amountMinor > left) throw AppError('adv_too_much', {'left': _money(left < 0 ? 0 : left)});
    final txn = Txn(
      id: nextId(),
      accountId: accountId,
      type: TxType.expense,
      amountMinor: amountMinor,
      occurredAt: givenOn.add(const Duration(hours: 12)),
      categoryId: categoryByKey('salary')?.id,
      description: translate(locale, 'adv.txn_description', {'name': employee.fullName}),
    );
    txns.add(txn);
    final adv = Advance(id: nextId(), employeeId: employee.id, period: m, givenOn: givenOn, amountMinor: amountMinor, accountId: accountId, txnId: txn.id, note: note.trim());
    _put('advances', [...advances.map((a) => a.toJson()), adv.toJson()]);
    auditLog('adv.given', '${employee.fullName} · ${_money(amountMinor)}');
    syncPayrollDraft(m);
    changed();
    return adv;
  }

  void updateAdvance(Advance a, {required DateTime givenOn, required int amountMinor, required int accountId, String note = ''}) {
    _guard(a.period);
    final e = employee(a.employeeId);
    if (e == null) throw AppError('adv_closed');
    if (amountMinor <= 0) throw AppError('amount');
    if (account(accountId) == null) throw AppError('no_account');
    final left = advanceAllowance(e, a.period) + a.amountMinor;
    if (amountMinor > left) throw AppError('adv_too_much', {'left': _money(left < 0 ? 0 : left)});
    final list = advances;
    final i = list.indexWhere((x) => x.id == a.id);
    if (i < 0) return;
    list[i]
      ..givenOn = givenOn
      ..amountMinor = amountMinor
      ..accountId = accountId
      ..note = note.trim();
    final t = txns.where((x) => x.id == list[i].txnId).firstOrNull;
    if (t != null) {
      t
        ..amountMinor = amountMinor
        ..accountId = accountId
        ..occurredAt = givenOn.add(const Duration(hours: 12));
    }
    _put('advances', list.map((x) => x.toJson()).toList());
    auditLog('adv.updated', '${e.fullName} · ${_money(amountMinor)}');
    syncPayrollDraft(a.period);
    changed();
  }

  void deleteAdvance(Advance a) {
    _guard(a.period);
    final name = employee(a.employeeId)?.fullName ?? '';
    txns.removeWhere((t) => t.id == a.txnId);
    _put('advances', advances.where((x) => x.id != a.id).map((x) => x.toJson()).toList());
    auditLog('adv.deleted', '$name · ${_money(a.amountMinor)}');
    syncPayrollDraft(a.period);
    changed();
  }

  // ───────────────────────── KPI bonuses ─────────────────────────

  void _kpiGuard(DateTime month, String title, int maxMinor, int bp) {
    if (serverMode) throw AppError('server_readonly');
    if (!canEditPeople) throw AppError('no_permission');
    if (monthClosed(month)) throw AppError('kpi_closed');
    if (title.trim().isEmpty) throw AppError('kpi_title');
    if (maxMinor <= 0) throw AppError('kpi_max');
    if (bp < 0 || bp > kKpiMaxBp) throw AppError('kpi_pct');
  }

  KpiBonus addKpi({required Employee employee, required DateTime month, required String title, required int maxBonusMinor, required int achievementBp, String note = ''}) {
    final m = monthStart(month);
    _kpiGuard(m, title, maxBonusMinor, achievementBp);
    final b = KpiBonus(id: nextId(), employeeId: employee.id, period: m, title: title.trim(), maxBonusMinor: maxBonusMinor, achievementBp: achievementBp, note: note.trim());
    _put('kpi', [...kpiBonuses.map((x) => x.toJson()), b.toJson()]);
    auditLog('kpi.added', '${employee.fullName} · ${_money(b.amountMinor)}');
    syncPayrollDraft(m);
    changed();
    return b;
  }

  void updateKpi(KpiBonus b, {required String title, required int maxBonusMinor, required int achievementBp, String note = ''}) {
    _kpiGuard(b.period, title, maxBonusMinor, achievementBp);
    final list = kpiBonuses;
    final i = list.indexWhere((x) => x.id == b.id);
    if (i < 0) return;
    list[i]
      ..title = title.trim()
      ..maxBonusMinor = maxBonusMinor
      ..achievementBp = achievementBp
      ..note = note.trim();
    _put('kpi', list.map((x) => x.toJson()).toList());
    auditLog('kpi.updated', '${employee(b.employeeId)?.fullName ?? ''} · ${_money(list[i].amountMinor)}');
    syncPayrollDraft(b.period);
    changed();
  }

  void deleteKpi(KpiBonus b) {
    if (serverMode) throw AppError('server_readonly');
    if (!canEditPeople) throw AppError('no_permission');
    if (monthClosed(b.period)) throw AppError('kpi_closed');
    _put('kpi', kpiBonuses.where((x) => x.id != b.id).map((x) => x.toJson()).toList());
    auditLog('kpi.deleted', '${employee(b.employeeId)?.fullName ?? ''} · ${_money(b.amountMinor)}');
    syncPayrollDraft(b.period);
    changed();
  }

  /// Brings the month's draft payroll in line with the bonuses and advances set after it was prepared. A line keeps any hand-made change of the
  /// gross pay: only the bonus difference is added to it and its taxes are counted again by the rules table. A closed month is left alone.
  void syncPayrollDraft(DateTime month) {
    final run = payrollFor(month);
    if (run == null || run.status != PayrollStatus.draft) return;
    final bonuses = kpiTotals(month), given = advanceTotals(month);
    for (final line in run.lines) {
      final id = line.employeeId;
      if (id == null) continue;
      final b = bonuses[id] ?? 0, a = given[id] ?? 0;
      if (b == line.bonus && a == line.advance) continue;
      line
        ..gross += b - line.bonus
        ..bonus = b
        ..advance = a;
      calc.recalcLine(line, run.period, company.budgetOrg);
    }
  }
}
