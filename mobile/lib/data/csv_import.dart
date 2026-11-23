import '../core/money.dart';
import 'models.dart';
import 'store.dart';

/// Bank statement import, the same steps as the web: read the delimited text, guess the columns, choose the date format and the account,
/// look at the rows, confirm. Everything is whole tiyin; nothing here uses a float.

/// Date formats the person can choose from (the web's list).
const List<String> kDateFormats = ['d.m.Y', 'Y-m-d', 'd/m/Y', 'm/d/Y'];

class CsvTable {
  CsvTable(this.delimiter, this.rows);
  final String delimiter;

  /// The first row is the header.
  final List<List<String>> rows;

  List<String> get headers => rows.isEmpty ? const [] : rows.first;
  List<List<String>> get body => rows.length <= 1 ? const [] : rows.sublist(1);
}

/// Reads delimited text: the delimiter (; , or tab) is the one the first line has most of; quotes and doubled quotes are understood;
/// blank rows are dropped.
CsvTable parseCsv(String input) {
  var text = input;
  if (text.startsWith('﻿')) text = text.substring(1);
  text = text.replaceAll('\r\n', '\n').replaceAll('\r', '\n');
  final firstLine = text.split('\n').firstWhere((l) => l.trim().isNotEmpty, orElse: () => '');
  var delimiter = ';';
  var best = -1;
  for (final d in [';', ',', '\t']) {
    final n = d.allMatches(firstLine).length;
    if (n > best) {
      best = n;
      delimiter = d;
    }
  }

  final rows = <List<String>>[];
  var row = <String>[];
  final cell = StringBuffer();
  var inQuotes = false;

  void endCell() {
    row.add(cell.toString().trim());
    cell.clear();
  }

  void endRow() {
    endCell();
    if (row.any((c) => c.isNotEmpty)) rows.add(row);
    row = <String>[];
  }

  for (var i = 0; i < text.length; i++) {
    final ch = text[i];
    if (inQuotes) {
      if (ch == '"') {
        if (i + 1 < text.length && text[i + 1] == '"') {
          cell.write('"');
          i++;
        } else {
          inQuotes = false;
        }
      } else {
        cell.write(ch);
      }
    } else if (ch == '"') {
      inQuotes = true;
    } else if (ch == delimiter) {
      endCell();
    } else if (ch == '\n') {
      endRow();
    } else {
      cell.write(ch);
    }
  }
  if (cell.isNotEmpty || row.isNotEmpty) endRow();
  return CsvTable(delimiter, rows);
}

/// Which column of the file holds what. `amount` is a signed amount; or `debit`/`credit` are two columns (debit = money out, credit = money in).
class ImportMap {
  ImportMap({this.date = 0, this.amount, this.debit, this.credit, this.description, this.type, this.reference, this.dateFormat = 'd.m.Y'});

  int date;
  int? amount, debit, credit, description, type, reference;
  String dateFormat;

  bool get useDebitCredit => amount == null && (debit != null || credit != null);
  bool get valid => date >= 0 && (amount != null || debit != null || credit != null);
}

const _aliases = <String, List<String>>{
  'date': ['sana', 'сана', 'дата', 'date', 'kun'],
  'amount': ['summa', 'сумма', 'amount', 'miqdor'],
  'debit': ['debet', 'дебет', 'debit', 'chiqim', 'расход'],
  'credit': ['kredit', 'кредит', 'credit', 'kirim', 'приход'],
  'description': ['izoh', 'maqsad', 'maqsadi', 'назначение', 'описание', 'комментарий', 'description', 'purpose', 'details'],
  'type': ['tur', 'turi', 'тип', 'вид', 'type', 'kirim/chiqim'],
  'reference': ['tolov raqami', 'hujjat raqami', 'hujjat', 'номер', 'документ', 'reference', 'ref', 'number'],
};

/// The column a header row most likely holds for each field; date and amount fall back to the first two columns when nothing matches.
ImportMap guessColumns(List<String> headers) {
  final norm = [for (final h in headers) h.toLowerCase().replaceAll(RegExp(r"[ʻ‘’']"), '').trim()];
  final taken = <int>{};
  int? find(String field) {
    for (var i = 0; i < norm.length; i++) {
      if (taken.contains(i) || norm[i].isEmpty) continue;
      final words = norm[i].split(RegExp(r'[^\p{L}/]+', unicode: true)).where((w) => w.isNotEmpty).toList();
      if (_aliases[field]!.contains(norm[i]) || words.any(_aliases[field]!.contains)) {
        taken.add(i);
        return i;
      }
    }
    return null;
  }

  final date = find('date');
  final amount = find('amount');
  final debit = find('debit');
  final credit = find('credit');
  final m = ImportMap(
    date: date ?? 0,
    amount: amount,
    debit: debit,
    credit: credit,
    description: find('description'),
    type: find('type'),
    reference: find('reference'),
  );
  // a file with neither an amount nor debit/credit columns: the second column is the amount (like the web)
  if (m.amount == null && m.debit == null && m.credit == null) m.amount = headers.length > 1 ? 1 : null;
  return m;
}

/// A date in one of [kDateFormats]; null when it does not match or is not a real date. A trailing time ("23.11.2026 10:15") is ignored.
DateTime? parseDate(String raw, String format) {
  final s = raw.trim().split(RegExp(r'[ T]')).first;
  final sep = format.contains('.') ? '.' : (format.contains('/') ? '/' : '-');
  final parts = s.split(sep);
  if (parts.length != 3 || parts.any((p) => int.tryParse(p) == null)) return null;
  final f = format.split(sep);
  int? d, mo, y;
  for (var i = 0; i < 3; i++) {
    final v = int.parse(parts[i]);
    switch (f[i]) {
      case 'd':
        d = v;
      case 'm':
        mo = v;
      case 'Y':
        y = v;
    }
  }
  if (d == null || mo == null || y == null || y < 1900 || y > 2200) return null;
  final dt = DateTime(y, mo, d);
  return dt.year == y && dt.month == mo && dt.day == d ? dt : null;
}

/// "1 234,56", "1,234.56", "-500 000", "(12 500,00)" to whole tiyin, signed (a minus or brackets mean money out). Null when it is not a number.
int? parseSignedAmount(String raw) {
  final t = raw.trim();
  if (t.isEmpty) return null;
  final negative = t.startsWith('-') || t.startsWith('−') || t.startsWith('(') || t.endsWith('-');
  var s = t.replaceAll(RegExp(r'[^0-9.,]'), '');
  if (s.isEmpty) return null;
  final lastDot = s.lastIndexOf('.'), lastComma = s.lastIndexOf(',');
  if (lastDot >= 0 && lastComma >= 0) {
    final decimal = lastDot > lastComma ? '.' : ',';
    final thousand = decimal == '.' ? ',' : '.';
    s = s.replaceAll(thousand, '').replaceAll(decimal, '.');
  } else if (lastComma >= 0 || lastDot >= 0) {
    final sep = lastComma >= 0 ? ',' : '.';
    if (sep.allMatches(s).length > 1) {
      s = s.replaceAll(sep, ''); // several of them: they are thousands
    } else {
      s = s.replaceAll(sep, '.');
    }
  }
  final minor = parseMinor(s);
  if (minor == null) return null;
  return negative ? -minor : minor;
}

TxType? _typeFromCell(String cell) {
  final c = cell.trim().toLowerCase();
  if (const ['income', 'credit', 'kirim', 'приход', 'доход', 'кредит', 'kredit'].contains(c)) return TxType.income;
  if (const ['expense', 'debit', 'chiqim', 'расход', 'дебет', 'debet'].contains(c)) return TxType.expense;
  return null;
}

enum ImportProblem { none, badDate, badAmount }

class ImportRow {
  ImportRow({required this.line, this.date, this.type = TxType.income, this.minor = 0, this.description = '', this.reference = '', this.problem = ImportProblem.none, this.duplicate = false});

  final int line; // line number in the file (the header is line 1)
  final DateTime? date;
  final TxType type;
  final int minor; // always positive
  final String description, reference;
  final ImportProblem problem;

  /// A row already in the books (same date, kind, amount and description).
  final bool duplicate;

  bool get ok => problem == ImportProblem.none;
}

String _dupKey(DateTime d, TxType t, int minor, String description) => '${d.year}-${d.month}-${d.day}|${t.name}|$minor|${description.trim().toLowerCase()}';

/// Turns the body of the table into rows by the column map; rows with an unreadable date or amount are marked, duplicates of the books are flagged.
List<ImportRow> buildImportRows(CsvTable table, ImportMap map, {Iterable<Txn> existing = const []}) {
  final known = {for (final t in existing) _dupKey(t.occurredAt, t.type, t.amountMinor, t.description)};
  final out = <ImportRow>[];
  var line = 1;
  for (final cells in table.body) {
    line++;
    String at(int? i) => i != null && i >= 0 && i < cells.length ? cells[i] : '';
    final date = parseDate(at(map.date), map.dateFormat);
    int? signed;
    TxType? kind;
    if (map.amount != null) {
      signed = parseSignedAmount(at(map.amount));
    } else {
      final d = map.debit == null ? null : parseSignedAmount(at(map.debit));
      final c = map.credit == null ? null : parseSignedAmount(at(map.credit));
      if (d != null && d != 0) {
        signed = -d.abs();
        kind = TxType.expense;
      } else if (c != null && c != 0) {
        signed = c.abs();
        kind = TxType.income;
      }
    }
    final description = at(map.description);
    final reference = at(map.reference);
    if (date == null) {
      out.add(ImportRow(line: line, description: description, reference: reference, problem: ImportProblem.badDate));
      continue;
    }
    if (signed == null || signed == 0) {
      out.add(ImportRow(line: line, date: date, description: description, reference: reference, problem: ImportProblem.badAmount));
      continue;
    }
    var type = kind ?? (signed < 0 ? TxType.expense : TxType.income);
    if (map.type != null) type = _typeFromCell(at(map.type)) ?? type;
    final minor = signed.abs();
    out.add(ImportRow(line: line, date: date, type: type, minor: minor, description: description, reference: reference, duplicate: known.contains(_dupKey(date, type, minor, description))));
  }
  return out;
}

extension CsvImportStore on AppStore {
  /// Adds the rows to the books on one account. Rows with a problem are always left out; duplicates unless [includeDuplicates].
  int importRows(List<ImportRow> rows, int accountId, {bool includeDuplicates = false}) {
    // like the web, import belongs to those who manage the books (the cashier enters operations by hand)
    if (!role.canApprove) throw AppError('no_permission');
    if (account(accountId) == null) throw AppError('no_account');
    var created = 0;
    for (final r in rows) {
      if (!r.ok || (r.duplicate && !includeDuplicates)) continue;
      txns.add(Txn(
        id: nextId(),
        accountId: accountId,
        type: r.type,
        amountMinor: r.minor,
        occurredAt: DateTime(r.date!.year, r.date!.month, r.date!.day, 12),
        description: r.description.length > 255 ? r.description.substring(0, 255) : r.description,
        reference: r.reference.length > 60 ? r.reference.substring(0, 60) : r.reference,
      ));
      created++;
    }
    auditLog('imp.done', '$created');
    changed();
    return created;
  }
}
