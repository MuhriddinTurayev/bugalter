import 'dart:async';
import 'dart:convert';

import 'package:flutter/foundation.dart' show ChangeNotifier, debugPrint;
import 'package:shared_preferences/shared_preferences.dart';

import 'api.dart';
import '../l10n/strings.dart';
import 'extras.dart';
import 'models.dart';
import 'seed.dart';
import 'tax.dart';

/// A business-rule error. The UI shows `t('err.<key>')`.
class AppError implements Exception {
  AppError(this.key, [this.args]);
  final String key;

  /// Placeholders of the message `err.<key>` (for example the amount that is still allowed).
  final Map<String, Object?>? args;
  @override
  String toString() => key;
}

class MonthPoint {
  MonthPoint(this.month, this.income, this.expense);
  final DateTime month;
  final int income, expense;
}

class AppNotice {
  AppNotice(this.key, this.kind, this.args, this.at);
  final String key; // stable id, used for read marks
  final String kind; // overdue | tax | payroll | proposal
  final Map<String, String> args;
  final DateTime at;
}

/// The whole company's books, kept on the device. Everything is an integer number of tiyin.
class AppStore extends ChangeNotifier {
  AppStore(this._prefs, this.book) : calc = TaxCalculator(book);

  /// The demo company lives under one key, the copy of the real company (from the server) under another, so the two never mix.
  String get _dbKey => serverMode ? 'bugalter.srv.v1' : 'bugalter.db.v2';
  final SharedPreferences _prefs;

  // ── the real company on the server ──
  /// True while the app shows the books of a company from the server (read from it, and kept on the phone for when there is no network).
  bool serverMode = false;
  String serverUrl = '';
  String? _token;
  Set<String> permissions = {};
  DateTime? lastSync;
  bool syncing = false;

  /// The reason the last sync failed (an [ApiException] code), or null; [offline] when the server could not be reached.
  String? syncError;
  bool offline = false;

  /// Changes made on the phone that the server has not got yet (in order). Each one is a request to the mobile API; they are sent as soon as there is network.
  final List<Map<String, dynamic>> outbox = [];
  final Map<int, int> _idMap = {};
  bool _pumping = false;
  Timer? _retry;

  /// The server's reason for the last change it refused (the screen shows it once and clears it).
  String? lastWriteError;

  /// The platform's domain, so that a company can be reached by its short name only.
  String serverDomain = 'bugalter.5-189-163-130.sslip.io';
  final TaxRuleBook book;
  final TaxCalculator calc;

  // preferences
  String locale = 'uz';
  bool dark = true;
  int? currentUserId;

  /// Where the person left the floating AI button (fractions of the free area, 0..1) and whether answers are read aloud.
  double orbX = .88, orbY = .6;
  bool aiSpeak = false;

  // data
  Company company = Company();
  final List<AppUser> users = [];
  final List<Contractor> contractors = [];
  final List<Account> accounts = [];
  final List<Category> categories = [];
  final List<Txn> txns = [];
  final List<Doc> docs = [];
  final List<Employee> employees = [];
  final List<PayrollRun> payrolls = [];
  final List<TaxReport> reports = [];
  final List<ChatMessage> chat = [];
  final List<AuditEntry> audit = [];
  final Set<String> readNotices = {};
  final Set<int> declinedProposals = {};

  /// Room for modules that keep their own JSON (cabinet, Didox, legal drafts, advances...): ext['<module>'] is saved with the books.
  final Map<String, dynamic> ext = {};
  int _seq = 1000;

  static Future<AppStore> open() async {
    final prefs = await SharedPreferences.getInstance();
    final store = AppStore(prefs, await TaxRuleBook.load());
    store._load();
    return store;
  }

  // ───────────────────────── persistence ─────────────────────────

  void _load() {
    locale = _prefs.getString('locale') ?? 'uz';
    dark = _prefs.getBool('dark') ?? true;
    currentUserId = _prefs.getInt('user');
    orbX = _prefs.getDouble('orbX') ?? .88;
    orbY = _prefs.getDouble('orbY') ?? .6;
    aiSpeak = _prefs.getBool('aiSpeak') ?? false;
    serverDomain = _prefs.getString('srv.domain') ?? serverDomain;
    _token = _prefs.getString('srv.token');
    serverUrl = _prefs.getString('srv.url') ?? '';
    serverMode = _token != null && serverUrl.isNotEmpty;
    final lastRaw = _prefs.getString('srv.last');
    lastSync = lastRaw == null ? null : DateTime.tryParse(lastRaw);
    final raw = _prefs.getString(_dbKey);
    if (raw == null) {
      if (serverMode) {
        // a session without its copy on the phone: sign in again
        _forgetServer();
        _load();
        return;
      }
      seedDemo(this);
      seedExtras(this);
      _persist();
      return;
    }
    try {
      final j = jsonDecode(raw) as Map<String, dynamic>;
      List<T> list<T>(String k, T Function(Map<String, dynamic>) f) =>
          (j[k] as List? ?? const []).map((e) => f(Map<String, dynamic>.from(e as Map))).toList();
      company = Company.fromJson(Map<String, dynamic>.from(j['company'] as Map));
      users..clear()..addAll(list('users', AppUser.fromJson));
      contractors..clear()..addAll(list('contractors', Contractor.fromJson));
      accounts..clear()..addAll(list('accounts', Account.fromJson));
      categories..clear()..addAll(list('categories', Category.fromJson));
      txns..clear()..addAll(list('txns', Txn.fromJson));
      docs..clear()..addAll(list('docs', Doc.fromJson));
      employees..clear()..addAll(list('employees', Employee.fromJson));
      payrolls..clear()..addAll(list('payrolls', PayrollRun.fromJson));
      reports..clear()..addAll(list('reports', TaxReport.fromJson));
      chat..clear()..addAll(list('chat', ChatMessage.fromJson));
      audit..clear()..addAll(list('audit', AuditEntry.fromJson));
      readNotices..clear()..addAll((j['read'] as List? ?? const []).cast<String>());
      declinedProposals..clear()..addAll((j['declined'] as List? ?? const []).cast<int>());
      ext
        ..clear()
        ..addAll(Map<String, dynamic>.from((j['ext'] as Map?) ?? const {}));
      _seq = (j['seq'] ?? 1000) as int;
      permissions = Set<String>.from((j['permissions'] as List? ?? const []).cast<String>());
      outbox
        ..clear()
        ..addAll((j['outbox'] as List? ?? const []).map((e) => Map<String, dynamic>.from(e as Map)));
      _idMap
        ..clear()
        ..addAll(((j['idMap'] as Map?) ?? const {}).map((k, v) => MapEntry(int.parse('$k'), (v as num).toInt())));
      if (serverMode && outbox.isNotEmpty) Future<void>.microtask(_pump);
    } catch (e) {
      debugPrint('Stored data could not be read, reseeding: $e');
      if (serverMode) {
        _forgetServer();
        _load();
        return;
      }
      seedDemo(this);
      seedExtras(this);
      _persist();
    }
  }

  /// Forgets the sign-in to the server and its copy on the phone (the demo company is untouched).
  void _forgetServer() {
    _prefs.remove('srv.token');
    _prefs.remove('srv.url');
    _prefs.remove('srv.last');
    _prefs.remove('bugalter.srv.v1');
    _prefs.remove('user');
    serverMode = false;
    serverUrl = '';
    _token = null;
    permissions = {};
    lastSync = null;
    currentUserId = null;
    outbox.clear();
    _idMap.clear();
    _retry?.cancel();
  }

  void _readOnly() {
    if (serverMode) throw AppError('server_readonly');
  }

  Timer? _saveTimer;

  void _persist() {
    _saveTimer?.cancel();
    _saveTimer = Timer(const Duration(milliseconds: 250), flush);
  }

  /// Writes everything now (also called when the app goes to the background).
  Future<void> flush() async {
    _saveTimer?.cancel();
    final j = {
      'company': company.toJson(),
      'users': users.map((e) => e.toJson()).toList(),
      'contractors': contractors.map((e) => e.toJson()).toList(),
      'accounts': accounts.map((e) => e.toJson()).toList(),
      'categories': categories.map((e) => e.toJson()).toList(),
      'txns': txns.map((e) => e.toJson()).toList(),
      'docs': docs.map((e) => e.toJson()).toList(),
      'employees': employees.map((e) => e.toJson()).toList(),
      'payrolls': payrolls.map((e) => e.toJson()).toList(),
      'reports': reports.map((e) => e.toJson()).toList(),
      'chat': chat.length > 80 ? chat.sublist(chat.length - 80).map((e) => e.toJson()).toList() : chat.map((e) => e.toJson()).toList(),
      'audit': audit.length > 200 ? audit.sublist(audit.length - 200).map((e) => e.toJson()).toList() : audit.map((e) => e.toJson()).toList(),
      'read': readNotices.toList(),
      'declined': declinedProposals.toList(),
      'ext': ext,
      'seq': _seq,
      'permissions': permissions.toList(),
      'outbox': outbox,
      'idMap': _idMap.map((k, v) => MapEntry('$k', v)),
    };
    await _prefs.setString(_dbKey, jsonEncode(j));
  }

  void changed() {
    _persist();
    notifyListeners();
  }

  /// Ids of records made on the phone are negative while they wait for the server (it gives the real ones).
  int nextId() => serverMode ? -(++_seq) : ++_seq;

  Future<void> resetDemo() async {
    ext.clear();
    seedDemo(this);
    seedExtras(this);
    readNotices.clear();
    declinedProposals.clear();
    chat.clear();
    audit.clear();
    final uid = users.where((u) => u.id == currentUserId);
    if (uid.isEmpty) await logout();
    changed();
  }

  // ───────────────────────── session & preferences ─────────────────────────

  AppUser? get user => users.where((u) => u.id == currentUserId).firstOrNull;
  Role get role => user?.role ?? Role.cashier;
  bool get signedIn => user != null;

  Future<bool> login(String login, String password) async {
    final l = login.trim().toLowerCase();
    final digits = l.replaceAll(RegExp(r'\D'), '');
    final u = users.where((u) => u.active && (u.login.toLowerCase() == l || (digits.length >= 9 && u.login.replaceAll(RegExp(r'\D'), '').endsWith(digits)))).firstOrNull;
    if (u == null || u.password != password) return false;
    currentUserId = u.id;
    await _prefs.setInt('user', u.id);
    _log('login', u.name);
    changed();
    return true;
  }

  Future<void> logout() async {
    if (serverMode) {
      await disconnectServer();
      return;
    }
    currentUserId = null;
    await _prefs.remove('user');
    notifyListeners();
  }

  // ───────────────────────── the server ─────────────────────────

  /// Signs in to a company on the server and loads its books. Throws [ApiException] (wrong password, no such company, no network...).
  Future<void> connectServer({required String address, required String login, required String password}) async {
    final base = ApiClient.resolve(address, serverDomain);
    if (base.isEmpty) throw ApiException(404, 'not_found');
    final api = ApiClient(base);
    final res = await api.post('/api/mobile/login', {'login': login.trim(), 'password': password});

    _token = res['token'] as String;
    serverUrl = base;
    serverMode = true;
    await _prefs.setString('srv.url', base);
    await _prefs.setString('srv.token', _token!);

    _clearData();
    _applyPerson(Map<String, dynamic>.from(res['user'] as Map), Map<String, dynamic>.from(res['company'] as Map));
    await _prefs.setInt('user', currentUserId!);
    await syncNow();
    if (!serverMode) throw ApiException(401, 'unauthenticated');
  }

  /// Signs out of the server and back to the demo company; the copy of the real company is wiped from the phone.
  Future<void> disconnectServer() async {
    _saveTimer?.cancel();
    _forgetServer();
    _clearData();
    _load();
    notifyListeners();
  }

  // ── changes made on the phone ──

  Map<String, dynamic> _contractorBody(Contractor c) {
    String? digits(String v) {
      final d = v.replaceAll(RegExp(r'\D'), '');
      return d.isEmpty ? null : d;
    }

    String? text(String v) => v.trim().isEmpty ? null : v.trim();
    return {
      'name': c.name.trim(), 'type': c.type.name, 'stir': digits(c.stir), 'phone': text(c.phone), 'email': text(c.email), 'address': text(c.address),
      'bankName': text(c.bankName), 'bankAccount': digits(c.bankAccount), 'mfo': digits(c.mfo), 'notes': text(c.notes),
    };
  }

  Map<String, dynamic> _txnBody(Txn t) => {
        'type': t.type.name, 'accountId': t.accountId, 'categoryId': t.categoryId, 'contractorId': t.contractorId, 'amountMinor': t.amountMinor,
        'occurredAt': t.occurredAt.toIso8601String().split('.').first, 'description': t.description.trim().isEmpty ? null : t.description.trim(),
        'reference': t.reference.trim().isEmpty ? null : t.reference.trim(),
      };

  Map<String, dynamic> _docBody(Doc d) {
    String day(DateTime x) => '${x.year.toString().padLeft(4, '0')}-${x.month.toString().padLeft(2, '0')}-${x.day.toString().padLeft(2, '0')}';
    return {
      'type': d.type.name, 'outgoing': d.outgoing, 'contractorId': d.contractorId, 'issueDate': day(d.issueDate), 'dueDate': d.dueDate == null ? null : day(d.dueDate!),
      'notes': d.notes.trim().isEmpty ? null : d.notes.trim(),
      'lines': [for (final l in d.lines) {'name': l.name, 'unit': l.unit.isEmpty ? null : l.unit, 'qtyMilli': l.qtyMilli, 'unitPriceMinor': l.unitPriceMinor, 'vatBp': l.vatBp}],
    };
  }

  /// Keeps a change to send to the server (in order) and starts sending.
  void _queue(String method, String path, {Map<String, dynamic>? body, int? id}) {
    if (!serverMode) return;
    outbox.add({'m': method, 'p': path, 'b': body, 'id': id});
    _persist();
    unawaited(_pump());
  }

  /// One call to the company's server by the screens (the agent panel, "check" buttons). Throws [ApiException]; a token that is no longer valid signs the person out.
  Future<Map<String, dynamic>> serverRequest(String method, String path, [Map<String, dynamic>? body]) async {
    if (!serverMode) throw ApiException(0, 'network');
    try {
      return await ApiClient(serverUrl, token: _token, lang: locale).send(method, path, body);
    } on ApiException catch (e) {
      if (e.unauthorized) await _expire();
      rethrow;
    }
  }

  /// A file of the company's server (the PDF of a letter).
  Future<List<int>> serverBytes(String path) async {
    if (!serverMode) throw ApiException(0, 'network');
    try {
      return await ApiClient(serverUrl, token: _token, lang: locale).getBytes(path);
    } on ApiException catch (e) {
      if (e.unauthorized) await _expire();
      rethrow;
    }
  }

  /// How many changes still wait for the network.
  int get pending => outbox.length;

  /// Sends the waiting changes one by one. A refused one is dropped (the server says why); no network keeps them for later. When all are sent, the company is read again.
  Future<void> _pump() async {
    if (_pumping || !serverMode || outbox.isEmpty) return;
    _pumping = true;
    _retry?.cancel();
    final api = ApiClient(serverUrl, token: _token, lang: locale);
    var expired = false;
    try {
      while (outbox.isNotEmpty && serverMode) {
        final op = outbox.first;
        final id = op['id'] as int?;
        var path = op['p'] as String;
        if (path.contains('{id}')) {
          final real = id == null ? null : (id < 0 ? _idMap[id] : id);
          if (real == null) {
            outbox.removeAt(0); // what it is about was never made on the server
            continue;
          }
          path = path.replaceAll('{id}', '$real');
        }
        final body = op['b'] == null ? null : Map<String, dynamic>.from(op['b'] as Map);
        final contractor = body?['contractorId'];
        if (contractor is int && contractor < 0) {
          final real = _idMap[contractor];
          if (real == null) {
            outbox.removeAt(0);
            continue;
          }
          body!['contractorId'] = real;
        }
        try {
          final res = await api.send(op['m'] as String, path, body);
          if (op['m'] == 'POST' && id != null && id < 0 && res['id'] is int) _idMap[id] = res['id'] as int;
          outbox.removeAt(0);
        } on ApiException catch (e) {
          if (e.network || e.code == 'timeout') break; // no network now: keep it, try again soon
          if (e.unauthorized) {
            expired = true;
            break;
          }
          // refused by the server (rights, rules, an ended subscription): dropped; the next reading puts the server's truth back
          outbox.removeAt(0);
          lastWriteError = e.message ?? e.code;
        }
      }
    } finally {
      _pumping = false;
      _persist();
    }
    if (expired) {
      await _expire();
    } else if (outbox.isEmpty) {
      await syncNow();
    } else {
      notifyListeners();
      _retry = Timer(const Duration(seconds: 30), () => unawaited(_pump()));
    }
  }

  /// The token is no longer valid: back to the sign-in page with a message.
  Future<void> _expire() async {
    _saveTimer?.cancel();
    _forgetServer();
    _clearData();
    _load();
    syncError = 'unauthenticated';
    notifyListeners();
  }

  Future<void> setServerDomain(String domain) async {
    serverDomain = domain.trim().toLowerCase().replaceAll(RegExp(r'^https?://|/+$'), '');
    await _prefs.setString('srv.domain', serverDomain);
    notifyListeners();
  }

  /// Reads the company's books (and the tax cabinet, letters, Didox and lawyer's drafts the person may see) from the server into the phone.
  Future<void> syncNow() async {
    if (!serverMode || syncing) return;
    // what was changed on the phone goes first (it comes back here when everything is sent); while something waits, the phone's copy is not overwritten
    if (outbox.isNotEmpty) {
      await _pump();
      if (outbox.isNotEmpty && serverMode) {
        syncError = 'network';
        offline = true;
        notifyListeners();
      }
      return;
    }
    syncing = true;
    syncError = null;
    notifyListeners();
    try {
      final api = ApiClient(serverUrl, token: _token, lang: locale);
      final snap = await api.get('/api/mobile/sync');
      final extras = <String, dynamic>{};
      await Future.wait([
        for (final part in const ['cabinet', 'letters', 'didox', 'legal', 'advances', 'kpi', 'signing-keys'])
          () async {
            try {
              extras[part] = await api.get('/api/mobile/$part');
            } on ApiException catch (e) {
              if (e.unauthorized) rethrow; // 403: the person has no right to this part
            }
          }(),
      ]);
      _applySnapshot(snap);
      ext['server'] = extras;
      lastSync = DateTime.now();
      await _prefs.setString('srv.last', lastSync!.toIso8601String());
      offline = false;
    } on ApiException catch (e) {
      if (e.unauthorized) {
        // the token is no longer valid (expired, password changed, person switched off)
        syncing = false;
        await _expire();
        return;
      }
      syncError = e.code;
      offline = e.network;
    } finally {
      syncing = false;
    }
    _persist();
    notifyListeners();
  }

  /// Syncs again when the copy on the phone is older than [age] (when the app opens or comes back).
  Future<void> syncIfStale([Duration age = const Duration(minutes: 3)]) async {
    if (!serverMode) return;
    if (lastSync == null || DateTime.now().difference(lastSync!) > age) await syncNow();
  }

  void _clearData() {
    contractors.clear();
    accounts.clear();
    categories.clear();
    txns.clear();
    docs.clear();
    employees.clear();
    payrolls.clear();
    reports.clear();
    chat.clear();
    audit.clear();
    users.clear();
    readNotices.clear();
    declinedProposals.clear();
    ext.clear();
    company = Company();
    currentUserId = null;
  }

  /// The signed-in person and the company, as the server describes them.
  void _applyPerson(Map<String, dynamic> person, Map<String, dynamic> comp) {
    final perms = Set<String>.from((person['permissions'] as List? ?? const []).cast<String>());
    permissions = perms;
    final role = switch (person['role']) {
      'owner' => Role.owner,
      _ => perms.any((p) => const {'reports.approve', 'payroll.approve', 'tax.pay', 'tax.reports', 'tax.cabinet', 'employees.view'}.contains(p)) ? Role.chiefAccountant : Role.cashier,
    };
    final id = person['id'] as int;
    users
      ..clear()
      ..add(AppUser(id: id, name: person['name'] as String, login: (person['email'] ?? '') as String, password: '', role: role, position: (person['title'] ?? '') as String));
    currentUserId = id;
    company = Company.fromJson(comp);
    final l = person['locale'] as String?;
    if (l != null && const ['uz', 'ru', 'tg'].contains(l) && _prefs.getString('locale') == null) locale = l;
  }

  /// Puts what the server sent in place of the books on the phone.
  void _applySnapshot(Map<String, dynamic> snap) {
    List<T> list<T>(String k, T Function(Map<String, dynamic>) f) => (snap[k] as List? ?? const []).map((e) => f(Map<String, dynamic>.from(e as Map))).toList();
    _applyPerson(Map<String, dynamic>.from(snap['user'] as Map), Map<String, dynamic>.from(snap['company'] as Map));
    contractors..clear()..addAll(list('contractors', Contractor.fromJson));
    accounts..clear()..addAll(list('accounts', Account.fromJson));
    categories..clear()..addAll(list('categories', Category.fromJson));
    txns..clear()..addAll(list('txns', Txn.fromJson));
    docs..clear()..addAll(list('docs', Doc.fromJson));
    employees..clear()..addAll(list('employees', Employee.fromJson));
    payrolls..clear()..addAll(list('payrolls', PayrollRun.fromJson));
    reports..clear()..addAll(list('reports', TaxReport.fromJson));
    // movements older than the ones sent still count in the balance of their account
    final older = (snap['olderBalance'] as Map?) ?? const {};
    for (final a in accounts) {
      final n = older['${a.id}'];
      if (n is num) a.openingMinor += n.toInt();
    }
    _labelAccounts();
  }

  /// An account that is only called by its kind (the server may store a translation key such as "ui.account_types.cash")
  /// is shown by the name of its kind in the language of the phone.
  void _labelAccounts() {
    final i = kLocales.indexOf(locale).clamp(0, 2);
    for (final a in accounts) {
      final names = kAllStrings['bank.type_${a.type.name}'];
      if (names == null) continue;
      if (a.name.startsWith('ui.') || names.contains(a.name)) a.name = names[i];
    }
  }

  Future<void> setLocale(String code) async {
    locale = code;
    if (serverMode) _labelAccounts();
    await _prefs.setString('locale', code);
    notifyListeners();
  }

  /// Remembers the floating AI button's place (no rebuild of the whole app: only saved).
  Future<void> saveOrb(double x, double y) async {
    orbX = x;
    orbY = y;
    await _prefs.setDouble('orbX', x);
    await _prefs.setDouble('orbY', y);
  }

  Future<void> setAiSpeak(bool v) async {
    aiSpeak = v;
    await _prefs.setBool('aiSpeak', v);
    notifyListeners();
  }

  Future<void> setDark(bool v) async {
    dark = v;
    await _prefs.setBool('dark', v);
    notifyListeners();
  }

  void _log(String action, [String subject = '', String actor = 'user']) {
    audit.add(AuditEntry(at: DateTime.now(), actor: actor, action: action, subject: subject));
  }

  void logAi(String action, [String subject = '']) {
    _log(action, subject, 'ai');
  }

  /// Writes an entry to the audit log for modules outside this file (advances, KPI, import...).
  void auditLog(String action, [String subject = '', String actor = 'user']) => _log(action, subject, actor);

  // ───────────────────────── lookups ─────────────────────────

  Contractor? contractor(int? id) => contractors.where((c) => c.id == id).firstOrNull;
  Account? account(int? id) => accounts.where((c) => c.id == id).firstOrNull;
  Category? category(int? id) => categories.where((c) => c.id == id).firstOrNull;
  Doc? doc(int? id) => docs.where((c) => c.id == id).firstOrNull;
  Employee? employee(int? id) => employees.where((c) => c.id == id).firstOrNull;
  Category? categoryByKey(String key) => categories.where((c) => c.key == key).firstOrNull;

  List<Account> get activeAccounts => accounts.where((a) => a.active).toList();

  // ───────────────────────── contractors ─────────────────────────

  void saveContractor(Contractor c) {
    final isNew = !contractors.any((e) => e.id == c.id);
    if (isNew) {
      contractors.add(c);
      _log('contractor.created', c.name);
    } else {
      _log('contractor.updated', c.name);
    }
    _queue(isNew ? 'POST' : 'PUT', isNew ? '/api/mobile/contractors' : '/api/mobile/contractors/{id}', body: _contractorBody(c), id: c.id);
    changed();
  }

  void toggleContractor(Contractor c) {
    c.active = !c.active;
    _log('contractor.toggled', c.name);
    _queue('PATCH', '/api/mobile/contractors/{id}/toggle', id: c.id);
    changed();
  }

  // ───────────────────────── accounts & categories ─────────────────────────

  void saveAccount(Account a) {
    _readOnly();
    if (!accounts.any((e) => e.id == a.id)) {
      accounts.add(a);
      _log('account.created', a.name);
    } else {
      _log('account.updated', a.name);
    }
    changed();
  }

  void saveCategory(Category c) {
    _readOnly();
    if (!categories.any((e) => e.id == c.id)) categories.add(c);
    _log('category.saved', c.name);
    changed();
  }

  void toggleCategory(Category c) {
    _readOnly();
    c.active = !c.active;
    changed();
  }

  int balanceOf(Account a) => a.openingMinor + txns.where((t) => t.accountId == a.id).fold(0, (s, t) => s + t.signed);
  int get totalBalance => accounts.where((a) => a.active).fold(0, (s, a) => s + balanceOf(a));

  // ───────────────────────── transactions ─────────────────────────

  void saveTxn(Txn t) {
    if (t.amountMinor <= 0) throw AppError('amount');
    final isNew = !txns.any((e) => e.id == t.id);
    if (isNew) {
      txns.add(t);
      _log('txn.created', '${t.type.name} ${t.amountMinor ~/ 100}');
    } else {
      _log('txn.updated', '${t.id}');
    }
    _queue(isNew ? 'POST' : 'PUT', isNew ? '/api/mobile/transactions' : '/api/mobile/transactions/{id}', body: _txnBody(t), id: t.id);
    changed();
  }

  void deleteTxn(Txn t) {
    txns.removeWhere((e) => e.id == t.id);
    _log('txn.deleted', '${t.id}');
    _queue('DELETE', '/api/mobile/transactions/{id}', id: t.id);
    changed();
  }

  List<Txn> txnsSorted() => [...txns]..sort((a, b) => b.occurredAt.compareTo(a.occurredAt));

  int sumTxns(TxType type, DateTime from, DateTime toExclusive) => txns
      .where((t) => t.type == type && !t.occurredAt.isBefore(from) && t.occurredAt.isBefore(toExclusive))
      .fold(0, (s, t) => s + t.amountMinor);

  /// Last [n] calendar months, oldest first.
  List<MonthPoint> months(int n, [DateTime? now]) {
    final d = now ?? DateTime.now();
    return [
      for (var i = n - 1; i >= 0; i--)
        () {
          final s = DateTime(d.year, d.month - i, 1);
          final e = DateTime(d.year, d.month - i + 1, 1);
          return MonthPoint(s, sumTxns(TxType.income, s, e), sumTxns(TxType.expense, s, e));
        }(),
    ];
  }

  /// Daily income for the last 7 days, oldest first, and the previous 7 days' total.
  ({List<int> days, int previous}) week([DateTime? now]) {
    final d = dateOnly(now ?? DateTime.now());
    final days = [
      for (var i = 6; i >= 0; i--) sumTxns(TxType.income, d.subtract(Duration(days: i)), d.subtract(Duration(days: i - 1))),
    ];
    final prev = sumTxns(TxType.income, d.subtract(const Duration(days: 13)), d.subtract(const Duration(days: 6)));
    return (days: days, previous: prev);
  }

  Map<int, int> expenseByCategory(DateTime from, DateTime toExclusive) {
    final m = <int, int>{};
    for (final t in txns) {
      if (t.type == TxType.expense && !t.occurredAt.isBefore(from) && t.occurredAt.isBefore(toExclusive)) {
        m[t.categoryId ?? 0] = (m[t.categoryId ?? 0] ?? 0) + t.amountMinor;
      }
    }
    return m;
  }

  // ───────────────────────── documents ─────────────────────────

  String nextNumber(DocType type, DateTime date) {
    final prefix = type == DocType.invoice ? 'INV' : 'ACT';
    final tag = '$prefix-${date.year}-';
    var max = 0;
    for (final d in docs) {
      if (d.number.startsWith(tag)) {
        final n = int.tryParse(d.number.substring(tag.length)) ?? 0;
        if (n > max) max = n;
      }
    }
    return '$tag${(max + 1).toString().padLeft(4, '0')}';
  }

  void saveDoc(Doc d) {
    if (d.lines.isEmpty) throw AppError('doc_lines');
    final isNew = !docs.any((e) => e.id == d.id);
    if (isNew) {
      docs.add(d);
      _log('doc.created', d.number);
    } else {
      _log('doc.updated', d.number);
    }
    _queue(isNew ? 'POST' : 'PUT', isNew ? '/api/mobile/documents' : '/api/mobile/documents/{id}', body: _docBody(d), id: d.id);
    changed();
  }

  void deleteDraft(Doc d) {
    if (d.status != DocStatus.draft) throw AppError('doc_not_draft');
    docs.removeWhere((e) => e.id == d.id);
    _log('doc.deleted', d.number);
    _queue('DELETE', '/api/mobile/documents/{id}', id: d.id);
    changed();
  }

  /// Moves a document along draft → sent → paid. Paying records the matching cash movement.
  void setDocStatus(Doc d, DocStatus to, {int? accountId}) {
    if (!d.status.next.contains(to)) throw AppError('doc_status');
    if (to == DocStatus.paid) {
      final acc = account(accountId) ?? activeAccounts.firstOrNull;
      if (acc == null) throw AppError('no_account');
      d.paidAt = DateTime.now();
      final cat = categoryByKey(d.outgoing ? (d.type == DocType.invoice ? 'sales' : 'services') : 'supplies');
      txns.add(Txn(
        id: nextId(),
        accountId: acc.id,
        type: d.outgoing ? TxType.income : TxType.expense,
        amountMinor: d.total,
        occurredAt: DateTime.now(),
        categoryId: cat?.id,
        contractorId: d.contractorId,
        documentId: d.id,
        description: d.number,
        reference: d.number,
      ));
    }
    d.status = to;
    _log('doc.${to.name}', d.number);
    _queue('PATCH', '/api/mobile/documents/{id}/status',
        body: {'status': to.name, if (to == DocStatus.paid) 'accountId': (account(accountId) ?? activeAccounts.firstOrNull)?.id}, id: d.id);
    changed();
  }

  int get receivable => docs.where((d) => d.open && d.outgoing).fold(0, (s, d) => s + d.total);
  int get payable => docs.where((d) => d.open && !d.outgoing).fold(0, (s, d) => s + d.total);
  List<Doc> overdueDocs([DateTime? now]) => docs.where((d) => d.isOverdue(now ?? DateTime.now())).toList();

  // ───────────────────────── employees & payroll ─────────────────────────

  void saveEmployee(Employee e) {
    _readOnly();
    if (!employees.any((x) => x.id == e.id)) {
      employees.add(e);
      _log('employee.created', e.fullName);
    } else {
      _log('employee.updated', e.fullName);
    }
    changed();
  }

  void deleteEmployee(Employee e) {
    _readOnly();
    employees.removeWhere((x) => x.id == e.id);
    _log('employee.deleted', e.fullName);
    changed();
  }

  PayrollRun? payrollFor(DateTime month) {
    final m = DateTime(month.year, month.month, 1);
    return payrolls.where((p) => p.period == m).firstOrNull;
  }

  /// Builds or refreshes the draft payroll of a month from the active employees; the amounts come from the rules table.
  PayrollRun preparePayroll(DateTime month) {
    _readOnly();
    final m = DateTime(month.year, month.month, 1);
    final existing = payrollFor(m);
    if (existing != null && existing.status != PayrollStatus.draft) throw AppError('payroll_not_draft');
    final active = employees.where((e) => e.status == EmployeeStatus.active).toList();
    if (active.isEmpty) throw AppError('payroll_no_employees');
    // KPI bonuses of the month are part of the gross pay (taxes are counted on them by the same rules); advances already given are deducted at the end
    final bonuses = kpiTotals(m), advances = advanceTotals(m);
    final lines = <PayrollLine>[];
    for (final e in active) {
      final line = calc.payrollLine(e: e, on: m, budgetOrg: company.budgetOrg);
      final bonus = bonuses[e.id] ?? 0;
      if (bonus > 0) {
        line.gross += bonus;
        calc.recalcLine(line, m, company.budgetOrg);
      }
      line
        ..bonus = bonus
        ..advance = advances[e.id] ?? 0;
      lines.add(line);
    }
    final run = existing ?? PayrollRun(id: nextId(), period: m);
    run.lines = lines;
    if (existing == null) payrolls.add(run);
    _log('payroll.prepared', '${m.month}.${m.year}');
    changed();
    return run;
  }

  void updatePayrollLine(PayrollRun run, PayrollLine line, int grossMinor) {
    _readOnly();
    if (run.status != PayrollStatus.draft) throw AppError('payroll_not_draft');
    line.gross = grossMinor;
    calc.recalcLine(line, run.period, company.budgetOrg);
    changed();
  }

  void approvePayroll(PayrollRun run) {
    _readOnly();
    if (!role.canApprove) throw AppError('no_permission');
    if (run.status != PayrollStatus.draft || run.lines.isEmpty) throw AppError('payroll_cannot_approve');
    run.status = PayrollStatus.approved;
    run.approvedBy = user?.name ?? '';
    _log('payroll.approved', '${run.period.month}.${run.period.year}');
    changed();
  }

  void reopenPayroll(PayrollRun run) {
    _readOnly();
    if (!role.canApprove) throw AppError('no_permission');
    if (run.status != PayrollStatus.approved) throw AppError('payroll_cannot_reopen');
    run.status = PayrollStatus.draft;
    run.approvedBy = '';
    _log('payroll.reopened', '${run.period.month}.${run.period.year}');
    changed();
  }

  void payPayroll(PayrollRun run, int accountId) {
    _readOnly();
    if (!role.canApprove) throw AppError('no_permission');
    if (run.status != PayrollStatus.approved) throw AppError('payroll_cannot_pay');
    // only what is still to pay: the advances were booked as expenses when they were given, so nothing is counted twice
    final payout = run.payout;
    if (payout > 0) {
      txns.add(Txn(
        id: nextId(),
        accountId: accountId,
        type: TxType.expense,
        amountMinor: payout,
        occurredAt: DateTime.now(),
        categoryId: categoryByKey('salary')?.id,
        description: 'payroll:${run.period.year}-${run.period.month.toString().padLeft(2, '0')}',
      ));
    }
    run.status = PayrollStatus.paid;
    run.paidAt = DateTime.now();
    run.paidAccountId = accountId;
    _log('payroll.paid', '${run.period.month}.${run.period.year}');
    changed();
  }

  // ───────────────────────── tax ─────────────────────────

  TaxComputation compute(TaxType type, DateTime start, DateTime end) {
    final e = end.add(const Duration(days: 1));
    switch (type) {
      case TaxType.vat:
        final inPeriod = docs.where((d) => (d.status == DocStatus.sent || d.status == DocStatus.paid) && !d.issueDate.isBefore(start) && d.issueDate.isBefore(e));
        final out = inPeriod.where((d) => d.outgoing);
        final inn = inPeriod.where((d) => !d.outgoing);
        final tax = out.fold<int>(0, (s, d) => s + d.vat) - inn.fold<int>(0, (s, d) => s + d.vat);
        return TaxComputation(out.fold<int>(0, (s, d) => s + d.subtotal), tax, [book.rule('vat.standard', end)]);
      case TaxType.turnover:
        final base = sumTxns(TxType.income, start, e);
        final r = book.rule('turnover.general', end);
        return TaxComputation(base, base * r.value ~/ 10000, [r]);
      case TaxType.profit:
        final ys = DateTime(end.year, 1, 1);
        final profit = sumTxns(TxType.income, ys, e) - sumTxns(TxType.expense, ys, e);
        final base = profit > 0 ? profit : 0;
        final r = book.rule('profit.general', end);
        return TaxComputation(base, base * r.value ~/ 10000, [r]);
      case TaxType.payroll:
        final runs = payrolls.where((p) => p.status != PayrollStatus.draft && !p.period.isBefore(start) && p.period.isBefore(e));
        return TaxComputation(
          runs.fold(0, (s, p) => s + p.gross),
          runs.fold(0, (s, p) => s + p.pit + p.social),
          [book.rule('pit.standard', end), book.rule(company.budgetOrg ? 'social.budget' : 'social.general', end)],
        );
    }
  }

  TaxReport? reportFor(Obligation o) => reports.where((r) => r.type == o.type && r.start == o.start).firstOrNull;

  TaxReport prepareReport(Obligation o) {
    _readOnly();
    final existing = reportFor(o);
    if (existing != null && existing.status == ReportStatus.submitted) throw AppError('report_submitted');
    final c = compute(o.type, o.start, o.end);
    final r = existing ?? TaxReport(id: nextId(), type: o.type, start: o.start, end: o.end, baseMinor: 0, taxMinor: 0);
    r
      ..baseMinor = c.baseMinor
      ..taxMinor = c.taxMinor
      ..status = ReportStatus.prepared
      ..approvedBy = ''
      ..rulesVerified = false // every rule is "needs verification" until an accountant verifies it in the platform
      ..updatedAt = DateTime.now();
    if (existing == null) reports.add(r);
    _log('report.prepared', '${o.type.name} ${o.start.month}.${o.start.year}');
    changed();
    return r;
  }

  void requestApproval(TaxReport r) {
    _readOnly();
    if (r.status != ReportStatus.prepared) throw AppError('report_state');
    r.status = ReportStatus.awaiting;
    r.updatedAt = DateTime.now();
    _log('report.requested', r.type.name);
    changed();
  }

  void approveReport(TaxReport r) {
    _readOnly();
    if (!role.canApprove) throw AppError('no_permission');
    if (r.status != ReportStatus.awaiting && r.status != ReportStatus.prepared) throw AppError('report_state');
    r.status = ReportStatus.approved;
    r.approvedBy = user?.name ?? '';
    r.updatedAt = DateTime.now();
    _log('report.approved', r.type.name);
    changed();
  }

  /// Sandbox submission: validates and records a test receipt. Nothing leaves the device.
  void submitReport(TaxReport r) {
    _readOnly();
    if (!role.canApprove) throw AppError('no_permission');
    if (r.status != ReportStatus.approved) throw AppError('report_not_approved');
    r.status = ReportStatus.submitted;
    r.receipt = 'SBX-${r.start.year}${r.start.month.toString().padLeft(2, '0')}-${r.id}';
    r.updatedAt = DateTime.now();
    _log('report.submitted', r.type.name);
    changed();
  }

  void withdrawApproval(TaxReport r) {
    _readOnly();
    if (r.status != ReportStatus.approved && r.status != ReportStatus.awaiting) throw AppError('report_state');
    r.status = ReportStatus.prepared;
    r.approvedBy = '';
    _log('report.cancelled', r.type.name);
    changed();
  }

  // ───────────────────────── company & people ─────────────────────────

  void saveCompany() {
    _readOnly();
    _log('company.updated', company.name);
    changed();
  }

  void saveUser(AppUser u) {
    _readOnly();
    if (!role.canManagePeople) throw AppError('no_permission');
    if (users.any((x) => x.login.toLowerCase() == u.login.toLowerCase() && x.id != u.id)) throw AppError('login_taken');
    if (!users.any((x) => x.id == u.id)) users.add(u);
    _log('user.saved', u.name);
    changed();
  }

  void changePassword(String oldPw, String newPw) {
    _readOnly();
    final u = user;
    if (u == null) throw AppError('no_permission');
    if (u.password != oldPw) throw AppError('wrong_password');
    if (newPw.length < 6) throw AppError('weak_password');
    u.password = newPw;
    _log('user.password', u.name);
    changed();
  }

  // ───────────────────────── notifications ─────────────────────────

  List<AppNotice> notices([DateTime? now]) {
    final d = now ?? DateTime.now();
    final out = <AppNotice>[];
    for (final doc in overdueDocs(d)) {
      out.add(AppNotice('overdue:${doc.id}', 'overdue', {'number': doc.number, 'name': contractor(doc.contractorId)?.name ?? ''}, doc.dueDate ?? d));
    }
    if (role.canSeeTax) {
      for (final o in calc.calendar(company, d)) {
        final r = reportFor(o);
        if (r?.status == ReportStatus.submitted) continue;
        final left = o.daysLeft(d);
        if (left <= 10) out.add(AppNotice('tax:${o.key}', 'tax', {'type': o.type.name, 'days': '$left'}, o.due));
      }
    }
    final thisMonth = DateTime(d.year, d.month, 1);
    final prev = DateTime(d.year, d.month - 1, 1);
    if (employees.isNotEmpty && payrollFor(prev) == null && role.canSeeTax) {
      out.add(AppNotice('payroll:${prev.year}-${prev.month}', 'payroll', {'month': '${prev.month}'}, thisMonth));
    }
    out.sort((a, b) => b.at.compareTo(a.at));
    return out;
  }

  int unreadCount() => notices().where((n) => !readNotices.contains(n.key)).length;

  void markAllRead() {
    readNotices.addAll(notices().map((n) => n.key));
    changed();
  }

  // ───────────────────────── chat ─────────────────────────

  void addChat(ChatMessage m) {
    chat.add(m);
    changed();
  }

  void clearChat() {
    chat.clear();
    changed();
  }
}
