import '../core/money.dart';
import '../l10n/strings.dart';
import 'cabinet.dart';
import 'models.dart';
import 'store.dart';
import 'tax.dart';

enum AiActionKind { proposals, tax, invoice }

/// A button that can sit under an assistant answer.
class AiAction {
  const AiAction(this.kind, [this.obligation]);
  final AiActionKind kind;
  final Obligation? obligation;
}

class Reply {
  const Reply(this.text, {this.chips = const [], this.action});
  final String text;
  final List<String> chips; // localized follow-up questions
  final AiAction? action;
}

/// Lower-cases, folds Tajik/Uzbek letters and drops apostrophes so Latin and Cyrillic spellings can be matched by simple stems.
String normalizeText(String s) {
  const map = {
    'ʻ': '', 'ʼ': '', "'": '', '’': '', '`': '', '‘': '',
    'ӯ': 'у', 'ў': 'у', 'ҳ': 'х', 'ҷ': 'ч', 'қ': 'к', 'ғ': 'г', 'ӣ': 'и', 'ё': 'е', 'ъ': '',
  };
  final b = StringBuffer();
  for (final r in s.toLowerCase().runes) {
    final ch = String.fromCharCode(r);
    b.write(map[ch] ?? ch);
  }
  return b.toString();
}

bool _any(String text, List<String> stems) => stems.any((k) => text.contains(normalizeText(k)));

class _Rule {
  const _Rule(this.cat, this.type, this.words);
  final String cat;
  final TxType type;
  final List<String> words;
}

/// The AI accountant that lives on the device. It reads the company's books, answers in uz / ru / tg and proposes categories.
/// It never invents a tax amount (they come from [AppStore.compute]) and it can neither sign, approve nor submit anything.
class Assistant {
  Assistant(this.store);
  final AppStore store;

  static const _cannot = ['imzola', 'imzo qil', 'tasdiqla', 'topshir', 'yubor', 'подпиш', 'подпис', 'утверд', 'утверж', 'сдай', 'сдать', 'отправ', 'имзо кун', 'имзо кард', 'тасдик кун', 'тасдик кард', 'супор', 'фирист'];
  static const _deadline = ['muddat', 'срок', 'дедлайн', 'мухлат'];
  static const _newdocVerbs = ['yarat', 'tuz', 'создай', 'создать', 'выстав', 'сделай', 'эчод', 'сохт'];
  static const _newdocNouns = ['faktura', 'hujjat', 'dalolatnoma', 'счет', 'фактур', 'документ', 'акт', 'хуччат', 'далолатнома'];
  static const _unclassified = ['kategoriyasiz', 'без категор', 'бе категор', 'tasniflanmagan', 'не классифиц', 'неклассифиц', 'тасниф нашуда', 'takliflar', 'предложен', 'пешниход'];
  static const _topcat = ['kategor', 'категор', 'eng kop', 'больше всего', 'бештарин', 'зиётарин', 'зиедтарин'];
  static const _overdue = ['muddati otgan', 'kechik', 'просроч', 'мухлаташ гузашта', 'дер шуд', 'overdue'];
  static const _payable = ['kreditor', 'biz qarz', 'qarzimiz', 'мы должн', 'кредитор', 'мо карздорем', 'наш долг'];
  static const _receivable = ['debitor', 'bizga qarz', 'kim qarz', 'qarzdor', 'нам должн', 'дебитор', 'кто долж', 'кто нам', 'ба мо карздор'];
  static const _payroll = ['ish haqi', 'oylik', 'xodim', 'зарплат', 'сотрудник', 'оклад', 'музд', 'корманд', 'маош'];
  static const _tax = ['soliq', 'qqs', 'jshds', 'налог', 'ндс', 'ндфл', 'андоз', 'ааи'];
  static const _profit = ['foyda', 'sof natija', 'прибыл', 'чистый результат', 'фоида', 'натича'];
  static const _income = ['kirim', 'daromad', 'tushum', 'выручк', 'доход', 'приход', 'поступлен', 'даромад', 'воридот'];
  static const _expense = ['chiqim', 'xarajat', 'расход', 'затрат', 'хароч', 'сарф'];
  static const _balance = ['balans', 'qoldiq', 'qoldig', 'hisoblar', 'hisobda', 'kassada', 'баланс', 'остат', 'сколько денег', 'на счет', 'бакия', 'чанд пул', 'мавчуд'];
  static const _help = ['yordam', 'nima qila', 'nimalar', 'помощ', 'умеешь', 'что можешь', 'кумак', 'чи кор', 'help'];
  static const _greet = ['salom', 'assalom', 'привет', 'здравствуй', 'добрый', 'салом', 'ассалом', 'hello'];
  static const _lastMonth = ['otgan oy', 'oldingi oy', 'прошл', 'предыдущ', 'мохи гузашта', 'гузашта мох', 'мохи пеш', 'last month'];

  static const _rules = <_Rule>[
    _Rule('bank_fees', TxType.expense, ['komissiya', 'комисси', 'bank xizmat', 'банковск']),
    _Rule('taxes', TxType.expense, ['soliq', 'qqs', 'jshds', 'налог', 'ндс', 'андоз']),
    _Rule('salary', TxType.expense, ['ish haqi', 'oylik', 'maosh', 'avans', 'зарплат', 'оклад', 'аванс', 'музд', 'маош']),
    _Rule('rent', TxType.expense, ['ijara', 'аренд', 'ичора']),
    _Rule('utilities', TxType.expense, ['elektr', 'gaz', 'suv', 'kommunal', 'internet', 'свет', 'электр', 'газ', 'вода', 'коммунал', 'интернет', 'барк']),
    _Rule('marketing', TxType.expense, ['reklam', 'marketing', 'google ads', 'facebook', 'instagram', 'target', 'реклам', 'маркетинг', 'таргет']),
    _Rule('transport', TxType.expense, ['taksi', 'taxi', 'yandex go', 'benzin', 'yoqilgi', 'transport', 'такси', 'бензин', 'топлив', 'транспорт', 'наклиет']),
    _Rule('supplies', TxType.expense, ['kanselyar', 'tovar', 'material', 'xom ashyo', 'канцел', 'товар', 'материал', 'мавод']),
    _Rule('services', TxType.income, ['xizmat', 'услуг', 'хизмат']),
  ];

  // ───────────────────────── answers ─────────────────────────

  Reply answer(String question, String locale) {
    final l = locale;
    final q = ' ${normalizeText(question)} ';
    String t(String key, [Map<String, Object?>? args]) => translate(l, key, args);
    List<String> chips(List<String> keys) => [for (final k in keys) t('ai.chip.$k')];
    final now = DateTime.now();

    if (_any(q, _cannot) && !_any(q, _deadline)) {
      return Reply(t('ai.r.cannot'), chips: chips(['tax', 'help']), action: _firstOpenObligation(now));
    }
    final cab = _cabinetIntent(question, q, l);
    if (cab != null) return cab;
    if (_any(q, _newdocVerbs) && _any(q, _newdocNouns)) {
      return Reply(t('ai.r.newdoc'), chips: chips(['overdue', 'receivable']), action: const AiAction(AiActionKind.invoice));
    }
    if (_any(q, _unclassified)) return _unclassifiedReply(l);
    if (_any(q, _topcat)) return _topCategories(l, _any(q, _lastMonth), now);
    if (_any(q, _overdue)) return _overdueReply(l, now);
    if (_any(q, _payable)) return _debts(l, outgoing: false);
    if (_any(q, _receivable)) return _debts(l, outgoing: true);
    if (_any(q, _payroll)) return _payrollReply(l);
    if (_any(q, _tax)) return _taxReply(l, now);

    final hasP = _any(q, _profit), hasI = _any(q, _income), hasE = _any(q, _expense);
    if (hasP || hasI || hasE) return _flow(l, _any(q, _lastMonth), now, hasP || (hasI && hasE), hasI, hasE);

    if (_any(q, _balance)) return _balanceReply(l);
    if (_any(q, _help)) return Reply(t('ai.r.help'), chips: chips(['balance', 'overdue', 'tax', 'payroll', 'unclassified']), action: const AiAction(AiActionKind.invoice));
    if (_any(q, _greet)) return Reply('${t('ai.r.hello')}\n\n${t('ai.r.help')}', chips: chips(['balance', 'overdue', 'tax']));
    return Reply(t('ai.r.unknown'), chips: chips(['balance', 'income', 'overdue', 'tax', 'help']));
  }

  String _m(int minor, String l) => formatMoney(minor, translate(l, 'currency'));

  String _d(DateTime d) => '${d.day.toString().padLeft(2, '0')}.${d.month.toString().padLeft(2, '0')}.${d.year}';

  String _monthLabel(String l, DateTime m) => '${translate(l, 'month.${m.month}')} ${m.year}';

  String _catName(String l, int? id) {
    final c = store.category(id);
    if (c == null) return translate(l, 'cat.none');
    return c.key != null ? translate(l, 'cat.${c.key}') : c.name;
  }

  List<String> _chips(String l, List<String> keys) => [for (final k in keys) translate(l, 'ai.chip.$k')];

  AiAction? _firstOpenObligation(DateTime now) {
    if (!store.role.canSeeTax) return null;
    try {
      final open = store.calc.calendar(store.company, now).where((o) => store.reportFor(o)?.status != ReportStatus.submitted);
      return open.isEmpty ? null : AiAction(AiActionKind.tax, open.first);
    } on MissingTaxRule {
      return null;
    }
  }

  Reply _balanceReply(String l) {
    final accounts = store.activeAccounts;
    final list = [for (final a in accounts) '• ${a.name}: ${_m(store.balanceOf(a), l)}'].join('\n');
    return Reply(translate(l, 'ai.r.balance', {'total': _m(store.totalBalance, l), 'list': list}).trim(), chips: _chips(l, ['income', 'overdue', 'tax']));
  }

  Reply _flow(String l, bool last, DateTime now, bool summary, bool hasI, bool hasE) {
    final month = DateTime(now.year, now.month - (last ? 1 : 0), 1);
    final to = DateTime(month.year, month.month + 1, 1);
    final income = store.sumTxns(TxType.income, month, to);
    final expense = store.sumTxns(TxType.expense, month, to);
    final period = _monthLabel(l, month);
    final String text;
    if (summary) {
      text = translate(l, 'ai.r.summary', {'period': period, 'income': _m(income, l), 'expense': _m(expense, l), 'profit': _m(income - expense, l)});
    } else if (hasI) {
      text = translate(l, 'ai.r.income', {'period': period, 'amount': _m(income, l)});
    } else {
      text = translate(l, 'ai.r.expense', {'period': period, 'amount': _m(expense, l)});
    }
    return Reply(text, chips: _chips(l, [last ? 'income' : 'last_month', 'topcat', 'balance']));
  }

  Reply _debts(String l, {required bool outgoing}) {
    final open = store.docs.where((d) => d.open && d.outgoing == outgoing).toList();
    final key = outgoing ? 'receivable' : 'payable';
    if (open.isEmpty) return Reply(translate(l, 'ai.r.${key}_none'), chips: _chips(l, ['overdue', 'balance']));
    final byParty = <int, int>{};
    for (final d in open) {
      byParty[d.contractorId] = (byParty[d.contractorId] ?? 0) + d.total;
    }
    final rows = byParty.entries.toList()..sort((a, b) => b.value.compareTo(a.value));
    final list = [for (final e in rows.take(5)) '• ${store.contractor(e.key)?.name ?? '—'}: ${_m(e.value, l)}'].join('\n');
    final total = open.fold(0, (s, d) => s + d.total);
    return Reply(translate(l, 'ai.r.$key', {'total': _m(total, l), 'n': open.length, 'list': list}), chips: _chips(l, ['overdue', outgoing ? 'balance' : 'receivable']));
  }

  Reply _overdueReply(String l, DateTime now) {
    final docs = store.overdueDocs(now)..sort((a, b) => a.dueDate!.compareTo(b.dueDate!));
    if (docs.isEmpty) return Reply(translate(l, 'dash.no_overdue'), chips: _chips(l, ['receivable', 'tax']));
    final today = DateTime(now.year, now.month, now.day);
    final list = [
      for (final d in docs.take(6))
        '• ${d.number} — ${store.contractor(d.contractorId)?.name ?? '—'}: ${_m(d.total, l)} (${translate(l, 'common.days', {'n': today.difference(DateTime(d.dueDate!.year, d.dueDate!.month, d.dueDate!.day)).inDays})})',
    ].join('\n');
    return Reply(translate(l, 'ai.r.overdue', {'n': docs.length, 'list': list}), chips: _chips(l, ['receivable', 'tax']));
  }

  Reply _taxReply(String l, DateTime now) {
    if (!store.role.canSeeTax) return Reply(translate(l, 'err.no_permission'));
    List<Obligation> open;
    try {
      open = store.calc.calendar(store.company, now).where((o) => store.reportFor(o)?.status != ReportStatus.submitted).take(4).toList();
    } on MissingTaxRule {
      return Reply(translate(l, 'tax.rules_missing'));
    }
    store.logAi('ai.tax_answer');
    if (open.isEmpty) return Reply(translate(l, 'ai.r.tax_none'), chips: _chips(l, ['payroll', 'balance']));
    final lines = <String>[];
    for (final o in open) {
      final r = store.reportFor(o);
      int? amount = r?.taxMinor;
      if (amount == null) {
        try {
          amount = store.compute(o.type, o.start, o.end).taxMinor;
        } on MissingTaxRule {
          amount = null;
        }
      }
      final left = o.daysLeft(now);
      final leftText = left < 0 ? translate(l, 'dash.days_late', {'n': -left}) : translate(l, 'dash.days_left', {'n': left});
      lines.add('• ${translate(l, 'taxtype.${o.type.name}')}: ${_d(o.due)} ($leftText) — ${amount == null ? '—' : _m(amount, l)}');
    }
    return Reply(
      translate(l, 'ai.r.tax', {'list': lines.join('\n'), 'note': translate(l, 'ai.r.tax_note')}),
      chips: _chips(l, ['payroll', 'balance']),
      action: AiAction(AiActionKind.tax, open.first),
    );
  }

  Reply _payrollReply(String l) {
    if (!store.role.canSeeTax) return Reply(translate(l, 'err.no_permission'));
    final active = store.employees.where((e) => e.status == EmployeeStatus.active).toList();
    final fund = active.fold(0, (s, e) => s + e.salaryMinor);
    final fundLine = translate(l, 'ai.r.payroll_fund', {'n': active.length, 'fund': _m(fund, l)});
    final runs = [...store.payrolls]..sort((a, b) => b.period.compareTo(a.period));
    store.logAi('ai.payroll_answer');
    if (runs.isEmpty) return Reply('${translate(l, 'ai.r.payroll_none')}\n$fundLine', chips: _chips(l, ['tax', 'balance']));
    final run = runs.first;
    final text = translate(l, 'ai.r.payroll', {
      'period': _monthLabel(l, run.period),
      'n': run.lines.length,
      'gross': _m(run.gross, l),
      'pit': _m(run.pit, l),
      'social': _m(run.social, l),
      'net': _m(run.net, l),
      'status': translate(l, 'ai.ps.${run.status.name}'),
    });
    return Reply('$text\n$fundLine', chips: _chips(l, ['tax', 'balance']));
  }

  Reply _topCategories(String l, bool last, DateTime now) {
    final month = DateTime(now.year, now.month - (last ? 1 : 0), 1);
    final by = store.expenseByCategory(month, DateTime(month.year, month.month + 1, 1));
    final total = by.values.fold(0, (a, b) => a + b);
    if (total == 0) return Reply(translate(l, 'ai.r.topcat_none'), chips: _chips(l, ['income', 'balance']));
    final rows = by.entries.toList()..sort((a, b) => b.value.compareTo(a.value));
    final list = [for (final e in rows.take(5)) '• ${_catName(l, e.key == 0 ? null : e.key)}: ${_m(e.value, l)} (${e.value * 100 ~/ total}%)'].join('\n');
    return Reply(translate(l, 'ai.r.topcat', {'period': _monthLabel(l, month), 'list': list}), chips: _chips(l, ['unclassified', 'last_month']));
  }

  Reply _unclassifiedReply(String l) {
    final n = store.txns.where((t) => t.categoryId == null).length;
    if (n == 0) return Reply(translate(l, 'ai.r.unclassified_none'), chips: _chips(l, ['topcat', 'balance']));
    final has = proposals().isNotEmpty;
    return Reply(translate(l, 'ai.r.unclassified', {'n': n}), chips: _chips(l, ['topcat']), action: has ? const AiAction(AiActionKind.proposals) : null);
  }

  // ───────────────────────── tax cabinet (sandbox): debts, letters, payment PROPOSALS ─────────────────────────

  static const _letterWords = [' xat ', 'xatlar', 'talabnoma', 'xabarnoma', 'письм', 'требован', 'уведомлен', 'мактуб', 'талабнома', 'огохинома'];
  static const _payVerbs = ['tolab ber', 'tolab qoy', 'tolayman', 'tolamoqchi', 'оплати', 'заплати', 'погаси', 'закрой долг', 'пардохт кун', 'пардохт мекунам'];
  static const _debtWords = ['qarz', 'долг', 'задолж', 'карз', 'penya', 'пеня'];
  static const _cabinetWords = ['kabinet', 'кабинет'];
  static const _taxWords = {
    'vat': ['qqs', 'ндс', 'ааи', 'kdv'],
    'profit': ['foyda', 'прибыл', 'фоида'],
    'pit': ['jshds', 'ндфл', 'даромади шахси'],
    'social': ['ijtimoiy', 'социальн', 'ичтимои'],
    'turnover': ['aylanma', 'оборот', 'гардиш'],
  };

  String? _taxFromText(String q) {
    for (final e in _taxWords.entries) {
      if (_any(q, e.value)) return e.key;
    }
    return null;
  }

  /// The largest number written in the question, in so'm, as tiyin (1 000 000 -> 100 000 000).
  int? _amountIn(String question) {
    int? best;
    for (final m in RegExp(r'\d[\d  ]{2,}').allMatches(question)) {
      final v = parseMinor(m.group(0)!.trim());
      if (v != null && v >= 100 && (best == null || v > best)) best = v;
    }
    return best;
  }

  Reply? _cabinetIntent(String question, String q, String l) {
    if (!store.role.canSeeTax) return null;
    final taxKey = _taxFromText(q);
    if (_any(q, _payVerbs) && (taxKey != null || _any(q, _tax))) return _payProposal(question, l, taxKey);
    if (_any(q, _letterWords)) return _lettersReply(l);
    if (_any(q, _cabinetWords) || (_any(q, _debtWords) && (_any(q, _tax) || taxKey != null))) return _cabinetDebtReply(l);
    return null;
  }

  List<String> _cabChips(String l, List<String> keys) => [for (final k in keys) translate(l, 'cab.chip.$k')];

  Reply _cabinetDebtReply(String l) {
    final d = CabinetData.of(store);
    store.logAi('cab_ai_answer', 'debt');
    final rows = d.balances.where((b) => b.debt > 0 || b.penalty > 0).toList();
    if (rows.isEmpty) return Reply(translate(l, 'cab.ai.debt_none'), chips: _cabChips(l, ['letters']));
    final list = [for (final b in rows) '• ${translate(l, 'cab.tax.${b.tax}')}: ${_m(b.debt, l)}${b.penalty > 0 ? ' (+${_m(b.penalty, l)})' : ''}'].join('\n');
    return Reply(translate(l, 'cab.ai.debt', {'total': _m(d.totalDebt, l), 'list': list, 'note': translate(l, 'cab.ai.debt_note')}), chips: _cabChips(l, ['pay', 'letters']));
  }

  Reply _lettersReply(String l) {
    final d = CabinetData.of(store);
    store.logAi('cab_ai_answer', 'letters');
    final all = d.letters;
    if (all.isEmpty) return Reply(translate(l, 'cab.ai.letters_none'));
    final list = [
      for (final x in all.take(5)) '• ${_d(x.date)} — ${x.titleIn(l)}${x.isDemand && !x.answered ? ' (${translate(l, 'let.unanswered')})' : ''}',
    ].join('\n');
    final open = all.where((x) => x.isDemand && !x.answered).length;
    return Reply(translate(l, 'cab.ai.letters', {'n': all.length, 'd': open, 'list': list}), chips: _cabChips(l, ['debt', 'pay']));
  }

  /// The assistant only PROPOSES a payment: a person must open "Tax payments" and press "Pay" (the approval is bound to tax, type and amount).
  Reply _payProposal(String question, String l, String? taxKey) {
    String t(String key, [Map<String, Object?>? args]) => translate(l, key, args);
    if (taxKey == null) return Reply(t('cab.ai.pay_which'), chips: _cabChips(l, ['pay', 'debt']));
    final d = CabinetData.of(store);
    final b = d.balances.where((e) => e.tax == taxKey).firstOrNull;
    final amount = _amountIn(question) ?? (b != null && b.debt > 0 ? b.debt : (b?.penalty ?? 0));
    if (amount <= 0) return Reply(t('cab.ai.pay_none', {'tax': t('cab.tax.$taxKey')}), chips: _cabChips(l, ['debt']));
    final kind = (b != null && b.debt == 0 && b.penalty > 0 && amount == b.penalty) ? '08108' : '08102';
    try {
      d.propose(tax: taxKey, kind: kind, amount: amount, actor: 'ai');
    } on AppError catch (e) {
      return Reply(t('err.${e.key}'), chips: _cabChips(l, ['debt']));
    }
    return Reply(t('cab.ai.pay_proposed', {'tax': t('cab.tax.$taxKey'), 'kind': t('pay.kind.$kind'), 'amount': _m(amount, l)}), chips: _cabChips(l, ['debt', 'letters']));
  }

  // ───────────────────────── proposals ─────────────────────────

  /// Category suggestions for transactions without a category. Applied only after a person decides.
  List<Proposal> proposals() {
    final out = <Proposal>[];
    for (final t in store.txns) {
      if (t.categoryId != null || store.declinedProposals.contains(t.id)) continue;
      final p = _propose(t);
      if (p != null) out.add(p);
    }
    return out;
  }

  Proposal? _propose(Txn t) {
    // 1) what the company already did with this counterparty
    if (t.contractorId != null) {
      final counts = <int, int>{};
      for (final o in store.txns) {
        if (o.id != t.id && o.contractorId == t.contractorId && o.type == t.type && o.categoryId != null) {
          counts[o.categoryId!] = (counts[o.categoryId!] ?? 0) + 1;
        }
      }
      if (counts.isNotEmpty) {
        final best = counts.entries.reduce((a, b) => b.value > a.value ? b : a);
        return Proposal(txnId: t.id, categoryId: best.key, reasonKey: 'ai.reason.history');
      }
    }
    // 2) keywords in the description (uz / ru / tg)
    final text = ' ${normalizeText(t.description)} ';
    for (final r in _rules) {
      if (r.type != t.type || !_any(text, r.words)) continue;
      final c = store.categoryByKey(r.cat);
      if (c != null) return Proposal(txnId: t.id, categoryId: c.id, reasonKey: 'ai.reason.cat.${r.cat}');
    }
    // 3) defaults
    if (t.type == TxType.income) {
      final c = store.categoryByKey('sales');
      return c == null ? null : Proposal(txnId: t.id, categoryId: c.id, reasonKey: 'ai.reason.income_default');
    }
    final c = store.categoryByKey('other_expense');
    return c == null ? null : Proposal(txnId: t.id, categoryId: c.id, reasonKey: 'ai.reason.default_expense');
  }
}
