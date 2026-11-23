import 'package:flutter/material.dart';

import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/cabinet.dart';
import '../../../data/models.dart';
import '../../widgets/glass.dart';
import 'cabinet_common.dart';
import 'letter_screen.dart';

/// The tax authority's demands and notices, kept with their full text. The app only reads them: it never presses the cabinet's answer button.
class LettersScreen extends StatefulWidget {
  const LettersScreen({super.key});

  @override
  State<LettersScreen> createState() => _LettersScreenState();
}

class _LettersScreenState extends State<LettersScreen> {
  int _filter = 0;

  @override
  Widget build(BuildContext context) {
    final s = context.store;
    final g = context.g;
    if (!s.role.canSeeTax) {
      return PageScaffold(title: context.t('nav.letters'), body: EmptyState(icon: Icons.lock_outline_rounded, title: context.t('tax.no_access_title'), text: context.t('tax.no_access_text')));
    }
    final d = CabinetData.of(s);
    final gate = cabGate(context, title: context.t('nav.letters'), synced: d.synced, allowed: d.canSeeLetters);
    if (gate != null) return gate;
    final all = d.letters;
    final list = switch (_filter) {
      1 => all.where((l) => l.isDemand).toList(),
      2 => all.where((l) => !l.isDemand).toList(),
      3 => all.where((l) => l.isDemand && !l.answered).toList(),
      _ => all,
    };

    return PageScaffold(
      title: context.t('nav.letters'),
      subtitle: context.t('let.subtitle'),
      body: ListView(
        padding: EdgeInsets.fromLTRB(16, 8, 16, context.navSpace + 8),
        children: [
          const Reveal(child: DemoNote()),
          const SizedBox(height: 12),
          Reveal(
            index: 1,
            child: ChipBar(
              labels: [context.t('let.f.all'), context.t('let.f.demands'), context.t('let.f.notices'), context.t('let.f.open')],
              index: _filter,
              onChanged: (i) => setState(() => _filter = i),
            ),
          ),
          const SizedBox(height: 14),
          if (list.isEmpty)
            EmptyState(icon: Icons.mail_outline_rounded, title: context.t('let.empty'))
          else
            for (var i = 0; i < list.length; i++)
              Reveal(
                index: i + 2,
                child: Padding(
                  padding: const EdgeInsets.only(bottom: 10),
                  child: SoftTile(
                    onTap: () => pushPage(context, LetterScreen(letterId: list[i].id)),
                    padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Row(children: [
                        Expanded(child: Wrap(spacing: 8, runSpacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [LetterKindPill(list[i]), letterDeadlinePill(context, list[i])])),
                        if (list[i].unread) Container(width: 10, height: 10, decoration: BoxDecoration(color: g.accent, shape: BoxShape.circle)),
                      ]),
                      const SizedBox(height: 8),
                      Text(list[i].titleIn(s.locale), maxLines: 3, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontWeight: list[i].unread ? FontWeight.w800 : FontWeight.w700, fontSize: 15, height: 1.25)),
                      const SizedBox(height: 4),
                      Text('${list[i].number.isEmpty ? '' : '${context.t('let.number', {'number': list[i].number})} · '}${context.date(list[i].date)}', style: TextStyle(color: g.textDim, fontSize: 12.5)),
                      if (list[i].deadline != null && !list[i].answered)
                        Padding(padding: const EdgeInsets.only(top: 2), child: Text(context.t('let.answer_by', {'date': context.date(list[i].deadline)}), style: TextStyle(color: g.textDim, fontSize: 12.5))),
                    ]),
                  ),
                ),
              ),
          const SizedBox(height: 4),
          Text(context.t(d.live ? 'let.live_hint' : 'let.never_press'), style: TextStyle(color: g.textDim, fontSize: 12, height: 1.35)),
        ],
      ),
    );
  }
}
