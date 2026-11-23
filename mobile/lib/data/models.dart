import '../core/money.dart';

DateTime dateOnly(DateTime d) => DateTime(d.year, d.month, d.day);
DateTime? _d(dynamic v) => v == null ? null : DateTime.parse(v as String);
String? _s(DateTime? d) => d?.toIso8601String();

enum Role { owner, chiefAccountant, cashier }

extension RoleX on Role {
  String get key => switch (this) { Role.owner => 'owner', Role.chiefAccountant => 'chief_accountant', Role.cashier => 'cashier' };
  bool get canApprove => this != Role.cashier;
  bool get canSeeTax => this != Role.cashier;
  bool get canManagePeople => this == Role.owner;
}

enum ContractorType { customer, supplier, both }

class Contractor {
  Contractor({
    required this.id,
    required this.name,
    this.stir = '',
    this.type = ContractorType.customer,
    this.phone = '',
    this.email = '',
    this.address = '',
    this.bankName = '',
    this.bankAccount = '',
    this.mfo = '',
    this.notes = '',
    this.active = true,
  });

  final int id;
  String name, stir, phone, email, address, bankName, bankAccount, mfo, notes;
  ContractorType type;
  bool active;

  Map<String, dynamic> toJson() => {
        'id': id, 'name': name, 'stir': stir, 'type': type.name, 'phone': phone, 'email': email,
        'address': address, 'bankName': bankName, 'bankAccount': bankAccount, 'mfo': mfo, 'notes': notes, 'active': active,
      };

  factory Contractor.fromJson(Map<String, dynamic> j) => Contractor(
        id: j['id'] as int, name: j['name'] as String, stir: (j['stir'] ?? '') as String,
        type: ContractorType.values.byName((j['type'] ?? 'customer') as String),
        phone: (j['phone'] ?? '') as String, email: (j['email'] ?? '') as String, address: (j['address'] ?? '') as String,
        bankName: (j['bankName'] ?? '') as String, bankAccount: (j['bankAccount'] ?? '') as String,
        mfo: (j['mfo'] ?? '') as String, notes: (j['notes'] ?? '') as String, active: (j['active'] ?? true) as bool,
      );
}

enum AccountType { cash, bank }

class Account {
  Account({required this.id, required this.name, required this.type, this.bankName = '', this.accountNumber = '', this.openingMinor = 0, this.active = true});

  final int id;
  String name, bankName, accountNumber;
  AccountType type;
  int openingMinor;
  bool active;

  Map<String, dynamic> toJson() => {
        'id': id, 'name': name, 'type': type.name, 'bankName': bankName, 'accountNumber': accountNumber,
        'openingMinor': openingMinor, 'active': active,
      };

  factory Account.fromJson(Map<String, dynamic> j) => Account(
        id: j['id'] as int, name: j['name'] as String, type: AccountType.values.byName(j['type'] as String),
        bankName: (j['bankName'] ?? '') as String, accountNumber: (j['accountNumber'] ?? '') as String,
        openingMinor: (j['openingMinor'] ?? 0) as int, active: (j['active'] ?? true) as bool,
      );
}

enum TxType { income, expense }

/// A category. System categories carry a [key] and are translated by the UI; custom ones carry their own [name].
class Category {
  Category({required this.id, this.key, this.name = '', required this.type, this.active = true});

  final int id;
  final String? key;
  String name;
  TxType type;
  bool active;

  Map<String, dynamic> toJson() => {'id': id, 'key': key, 'name': name, 'type': type.name, 'active': active};

  factory Category.fromJson(Map<String, dynamic> j) => Category(
        id: j['id'] as int, key: j['key'] as String?, name: (j['name'] ?? '') as String,
        type: TxType.values.byName(j['type'] as String), active: (j['active'] ?? true) as bool,
      );
}

class Txn {
  Txn({
    required this.id,
    required this.accountId,
    required this.type,
    required this.amountMinor,
    required this.occurredAt,
    this.categoryId,
    this.contractorId,
    this.documentId,
    this.description = '',
    this.reference = '',
  });

  final int id;
  int accountId;
  int? categoryId, contractorId, documentId;
  TxType type;
  int amountMinor;
  DateTime occurredAt;
  String description, reference;

  int get signed => type == TxType.income ? amountMinor : -amountMinor;

  Map<String, dynamic> toJson() => {
        'id': id, 'accountId': accountId, 'categoryId': categoryId, 'contractorId': contractorId, 'documentId': documentId,
        'type': type.name, 'amountMinor': amountMinor, 'occurredAt': _s(occurredAt), 'description': description, 'reference': reference,
      };

  factory Txn.fromJson(Map<String, dynamic> j) => Txn(
        id: j['id'] as int, accountId: j['accountId'] as int, categoryId: j['categoryId'] as int?,
        contractorId: j['contractorId'] as int?, documentId: j['documentId'] as int?,
        type: TxType.values.byName(j['type'] as String), amountMinor: j['amountMinor'] as int,
        occurredAt: _d(j['occurredAt'])!, description: (j['description'] ?? '') as String, reference: (j['reference'] ?? '') as String,
      );
}

enum DocType { invoice, act }

enum DocStatus { draft, sent, paid, cancelled }

extension DocStatusX on DocStatus {
  List<DocStatus> get next => switch (this) {
        DocStatus.draft => [DocStatus.sent, DocStatus.cancelled],
        DocStatus.sent => [DocStatus.paid, DocStatus.cancelled],
        _ => const [],
      };
}

class DocLine {
  DocLine({required this.name, this.unit = '', this.qtyMilli = 1000, required this.unitPriceMinor, this.vatBp = 0});

  String name, unit;
  int qtyMilli; // quantity * 1000, so there is no floating point in money maths
  int unitPriceMinor;
  int vatBp;

  int get subtotal => (qtyMilli * unitPriceMinor + 500) ~/ 1000;
  int get vat => percentOf(subtotal, vatBp);
  int get total => subtotal + vat;

  Map<String, dynamic> toJson() => {'name': name, 'unit': unit, 'qtyMilli': qtyMilli, 'unitPriceMinor': unitPriceMinor, 'vatBp': vatBp};

  factory DocLine.fromJson(Map<String, dynamic> j) => DocLine(
        name: j['name'] as String, unit: (j['unit'] ?? '') as String, qtyMilli: j['qtyMilli'] as int,
        unitPriceMinor: j['unitPriceMinor'] as int, vatBp: (j['vatBp'] ?? 0) as int,
      );
}

class Doc {
  Doc({
    required this.id,
    required this.type,
    required this.number,
    required this.contractorId,
    required this.issueDate,
    this.dueDate,
    this.status = DocStatus.draft,
    this.outgoing = true,
    List<DocLine>? lines,
    this.notes = '',
    this.paidAt,
  }) : lines = lines ?? [];

  final int id;
  DocType type;
  String number;
  int contractorId;
  DateTime issueDate;
  DateTime? dueDate, paidAt;
  DocStatus status;
  bool outgoing; // true: we issued it (receivable). false: a supplier issued it to us (payable).
  List<DocLine> lines;
  String notes;

  int get subtotal => lines.fold(0, (a, l) => a + l.subtotal);
  int get vat => lines.fold(0, (a, l) => a + l.vat);
  int get total => subtotal + vat;
  bool get open => status == DocStatus.sent;
  bool isOverdue(DateTime now) => open && dueDate != null && dateOnly(dueDate!).isBefore(dateOnly(now));

  Map<String, dynamic> toJson() => {
        'id': id, 'type': type.name, 'number': number, 'contractorId': contractorId, 'issueDate': _s(issueDate),
        'dueDate': _s(dueDate), 'status': status.name, 'outgoing': outgoing, 'lines': lines.map((e) => e.toJson()).toList(),
        'notes': notes, 'paidAt': _s(paidAt),
      };

  factory Doc.fromJson(Map<String, dynamic> j) => Doc(
        id: j['id'] as int, type: DocType.values.byName(j['type'] as String), number: j['number'] as String,
        contractorId: j['contractorId'] as int, issueDate: _d(j['issueDate'])!, dueDate: _d(j['dueDate']),
        status: DocStatus.values.byName(j['status'] as String), outgoing: (j['outgoing'] ?? true) as bool,
        lines: (j['lines'] as List).map((e) => DocLine.fromJson(Map<String, dynamic>.from(e as Map))).toList(),
        notes: (j['notes'] ?? '') as String, paidAt: _d(j['paidAt']),
      );
}

enum EmployeeStatus { active, inactive }

class Employee {
  Employee({
    required this.id,
    required this.fullName,
    this.pinfl = '',
    this.position = '',
    this.department = '',
    this.phone = '',
    this.hiredOn,
    this.status = EmployeeStatus.active,
    this.salaryMinor = 0,
  });

  final int id;
  String fullName, pinfl, position, department, phone;
  DateTime? hiredOn;
  EmployeeStatus status;
  int salaryMinor;

  Map<String, dynamic> toJson() => {
        'id': id, 'fullName': fullName, 'pinfl': pinfl, 'position': position, 'department': department, 'phone': phone,
        'hiredOn': _s(hiredOn), 'status': status.name, 'salaryMinor': salaryMinor,
      };

  factory Employee.fromJson(Map<String, dynamic> j) => Employee(
        id: j['id'] as int, fullName: j['fullName'] as String, pinfl: (j['pinfl'] ?? '') as String,
        position: (j['position'] ?? '') as String, department: (j['department'] ?? '') as String,
        phone: (j['phone'] ?? '') as String, hiredOn: _d(j['hiredOn']),
        status: EmployeeStatus.values.byName((j['status'] ?? 'active') as String), salaryMinor: (j['salaryMinor'] ?? 0) as int,
      );
}

enum PayrollStatus { draft, approved, paid }

class PayrollLine {
  PayrollLine({required this.employeeId, required this.name, this.position = '', required this.gross, required this.pit, required this.inps, required this.social, this.bonus = 0, this.advance = 0});

  int? employeeId;
  String name, position;
  int gross, pit, inps, social;

  /// The part of [gross] that comes from KPI bonuses, and the advances already given (booked as expenses) that the month deducts.
  int bonus, advance;
  int get net => gross - pit;

  /// What is still to pay at the end of the month: the net pay less the advances already given.
  int get payout => net - advance;

  Map<String, dynamic> toJson() => {
        'employeeId': employeeId, 'name': name, 'position': position, 'gross': gross, 'pit': pit, 'inps': inps, 'social': social,
        'bonus': bonus, 'advance': advance,
      };

  factory PayrollLine.fromJson(Map<String, dynamic> j) => PayrollLine(
        employeeId: j['employeeId'] as int?, name: j['name'] as String, position: (j['position'] ?? '') as String,
        gross: j['gross'] as int, pit: j['pit'] as int, inps: j['inps'] as int, social: j['social'] as int,
        bonus: (j['bonus'] ?? 0) as int, advance: (j['advance'] ?? 0) as int,
      );
}

class PayrollRun {
  PayrollRun({required this.id, required this.period, this.status = PayrollStatus.draft, List<PayrollLine>? lines, this.approvedBy = '', this.paidAt, this.paidAccountId})
      : lines = lines ?? [];

  final int id;
  DateTime period; // first day of the month
  PayrollStatus status;
  List<PayrollLine> lines;
  String approvedBy;
  DateTime? paidAt;
  int? paidAccountId;

  int get gross => lines.fold(0, (a, l) => a + l.gross);
  int get pit => lines.fold(0, (a, l) => a + l.pit);
  int get inps => lines.fold(0, (a, l) => a + l.inps);
  int get social => lines.fold(0, (a, l) => a + l.social);
  int get net => lines.fold(0, (a, l) => a + l.net);
  int get bonuses => lines.fold(0, (a, l) => a + l.bonus);
  int get advances => lines.fold(0, (a, l) => a + l.advance);

  /// What is still to pay after the advances (the payment booked when the payroll is marked paid).
  int get payout => lines.fold(0, (a, l) => a + l.payout);

  Map<String, dynamic> toJson() => {
        'id': id, 'period': _s(period), 'status': status.name, 'lines': lines.map((e) => e.toJson()).toList(),
        'approvedBy': approvedBy, 'paidAt': _s(paidAt), 'paidAccountId': paidAccountId,
      };

  factory PayrollRun.fromJson(Map<String, dynamic> j) => PayrollRun(
        id: j['id'] as int, period: _d(j['period'])!, status: PayrollStatus.values.byName(j['status'] as String),
        lines: (j['lines'] as List).map((e) => PayrollLine.fromJson(Map<String, dynamic>.from(e as Map))).toList(),
        approvedBy: (j['approvedBy'] ?? '') as String, paidAt: _d(j['paidAt']), paidAccountId: j['paidAccountId'] as int?,
      );
}

enum TaxType { vat, turnover, profit, payroll }

enum ReportStatus { prepared, awaiting, approved, submitted }

/// A tax report prepared by the deterministic calculators; it only moves forward through a person's approval.
class TaxReport {
  TaxReport({
    required this.id,
    required this.type,
    required this.start,
    required this.end,
    required this.baseMinor,
    required this.taxMinor,
    this.status = ReportStatus.prepared,
    this.rulesVerified = false,
    this.approvedBy = '',
    this.receipt = '',
    DateTime? updatedAt,
  }) : updatedAt = updatedAt ?? DateTime.now();

  final int id;
  TaxType type;
  DateTime start, end, updatedAt;
  int baseMinor, taxMinor;
  ReportStatus status;
  bool rulesVerified;
  String approvedBy, receipt;

  Map<String, dynamic> toJson() => {
        'id': id, 'type': type.name, 'start': _s(start), 'end': _s(end), 'baseMinor': baseMinor, 'taxMinor': taxMinor,
        'status': status.name, 'rulesVerified': rulesVerified, 'approvedBy': approvedBy, 'receipt': receipt, 'updatedAt': _s(updatedAt),
      };

  factory TaxReport.fromJson(Map<String, dynamic> j) => TaxReport(
        id: j['id'] as int, type: TaxType.values.byName(j['type'] as String), start: _d(j['start'])!, end: _d(j['end'])!,
        baseMinor: j['baseMinor'] as int, taxMinor: j['taxMinor'] as int, status: ReportStatus.values.byName(j['status'] as String),
        rulesVerified: (j['rulesVerified'] ?? false) as bool, approvedBy: (j['approvedBy'] ?? '') as String,
        receipt: (j['receipt'] ?? '') as String, updatedAt: _d(j['updatedAt']),
      );
}

class Company {
  Company({this.name = '', this.stir = '', this.address = '', this.phone = '', this.regime = 'general', this.vatPayer = true, this.budgetOrg = false, this.director = ''});

  String name, stir, address, phone, director;
  String regime; // general | turnover
  bool vatPayer, budgetOrg;

  Map<String, dynamic> toJson() => {
        'name': name, 'stir': stir, 'address': address, 'phone': phone, 'regime': regime, 'vatPayer': vatPayer,
        'budgetOrg': budgetOrg, 'director': director,
      };

  factory Company.fromJson(Map<String, dynamic> j) => Company(
        name: (j['name'] ?? '') as String, stir: (j['stir'] ?? '') as String, address: (j['address'] ?? '') as String,
        phone: (j['phone'] ?? '') as String, regime: (j['regime'] ?? 'general') as String,
        vatPayer: (j['vatPayer'] ?? true) as bool, budgetOrg: (j['budgetOrg'] ?? false) as bool, director: (j['director'] ?? '') as String,
      );
}

class AppUser {
  AppUser({required this.id, required this.name, required this.login, required this.password, required this.role, this.position = '', this.active = true});

  final int id;
  String name, login, password, position;
  Role role;
  bool active;

  Map<String, dynamic> toJson() => {
        'id': id, 'name': name, 'login': login, 'password': password, 'role': role.name, 'position': position, 'active': active,
      };

  factory AppUser.fromJson(Map<String, dynamic> j) => AppUser(
        id: j['id'] as int, name: j['name'] as String, login: j['login'] as String, password: j['password'] as String,
        role: Role.values.byName(j['role'] as String), position: (j['position'] ?? '') as String, active: (j['active'] ?? true) as bool,
      );
}

/// An AI proposal: a suggested category for an unclassified transaction, applied only after a person decides.
class Proposal {
  Proposal({required this.txnId, required this.categoryId, required this.reasonKey, this.decided = false});

  final int txnId;
  final int categoryId;
  final String reasonKey;
  bool decided;
}

class ChatMessage {
  ChatMessage({required this.fromUser, required this.text, DateTime? at, this.chips = const [], this.voice = false}) : at = at ?? DateTime.now();

  final bool fromUser;
  final String text;
  final DateTime at;
  final List<String> chips;

  /// True when the person spoke this message (the words are the transcript).
  final bool voice;

  Map<String, dynamic> toJson() => {'u': fromUser, 't': text, 'at': _s(at), 'v': voice};

  factory ChatMessage.fromJson(Map<String, dynamic> j) => ChatMessage(fromUser: j['u'] as bool, text: j['t'] as String, at: _d(j['at']), voice: (j['v'] ?? false) as bool);
}

class AuditEntry {
  AuditEntry({required this.at, required this.actor, required this.action, this.subject = ''});

  final DateTime at;
  final String actor; // user | ai | system
  final String action; // key
  final String subject;

  Map<String, dynamic> toJson() => {'at': _s(at), 'actor': actor, 'action': action, 'subject': subject};

  factory AuditEntry.fromJson(Map<String, dynamic> j) =>
      AuditEntry(at: _d(j['at'])!, actor: j['actor'] as String, action: j['action'] as String, subject: (j['subject'] ?? '') as String);
}
