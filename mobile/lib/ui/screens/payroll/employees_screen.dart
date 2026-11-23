import 'package:flutter/material.dart';

import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/models.dart';
import '../../widgets/glass.dart';
import 'employee_form.dart';

/// The staff list with search and an active / inactive filter.
class EmployeesScreen extends StatefulWidget {
  const EmployeesScreen({super.key});

  @override
  State<EmployeesScreen> createState() => _EmployeesScreenState();
}

class _EmployeesScreenState extends State<EmployeesScreen> {
  int _filter = 1; // 0 all, 1 active, 2 inactive
  String _query = '';

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final s = context.store;
    final q = _query.trim().toLowerCase();
    final list = s.employees.where((e) {
      if (_filter == 1 && e.status != EmployeeStatus.active) return false;
      if (_filter == 2 && e.status != EmployeeStatus.inactive) return false;
      return q.isEmpty || '${e.fullName} ${e.position} ${e.department} ${e.phone}'.toLowerCase().contains(q);
    }).toList()
      ..sort((a, b) => a.fullName.compareTo(b.fullName));
    final fund = s.employees.where((e) => e.status == EmployeeStatus.active).fold(0, (a, e) => a + e.salaryMinor);

    return PageScaffold(
      title: context.t('nav.employees'),
      fab: AppButton(label: context.t('employees.add'), icon: Icons.person_add_alt_1_rounded, expanded: false, onPressed: () => pushPage(context, const EmployeeFormScreen())),
      body: ListView(
        padding: EdgeInsets.fromLTRB(0, 8, 0, 100 + MediaQuery.viewPaddingOf(context).bottom),
        children: [
          Reveal(
            child: GlassCard(
              gradient: LinearGradient(colors: [g.accent.withValues(alpha: .22), g.accent2.withValues(alpha: .16)]),
              child: Row(children: [
                Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(context.t('employees.total_salary'), style: TextStyle(color: g.textDim, fontWeight: FontWeight.w700)),
                    const SizedBox(height: 4),
                    AmountText(fund, size: 26),
                  ]),
                ),
                const SizedBox(width: 10),
                StatusPill(label: context.t('common.items', {'n': s.employees.where((e) => e.status == EmployeeStatus.active).length}), color: g.accent, icon: Icons.groups_rounded),
              ]),
            ),
          ),
          const SizedBox(height: 14),
          GlassField(label: context.t('common.search'), icon: Icons.search_rounded, onChanged: (v) => setState(() => _query = v)),
          const SizedBox(height: 10),
          ChipBar(labels: [context.t('common.all'), context.t('common.active'), context.t('common.inactive')], index: _filter, onChanged: (i) => setState(() => _filter = i)),
          const SizedBox(height: 12),
          AnimatedSwitcher(
            duration: const Duration(milliseconds: 280),
            child: list.isEmpty
                ? Padding(
                    key: const ValueKey('empty'),
                    padding: const EdgeInsets.only(top: 24),
                    child: EmptyState(icon: Icons.groups_outlined, title: context.t('employees.empty_title'), text: context.t('employees.empty_text')),
                  )
                : Column(
                    key: ValueKey('$_filter$q'),
                    children: [
                      for (var i = 0; i < list.length; i++)
                        Padding(
                          padding: const EdgeInsets.only(bottom: 10),
                          child: Reveal(
                            key: ValueKey(list[i].id),
                            index: i,
                            dy: 12,
                            child: GlassCard(
                              blur: false,
                              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
                              onTap: () => pushPage(context, EmployeeFormScreen(employee: list[i])),
                              child: RowTile(
                                leading: Avatar(name: list[i].fullName, size: 46),
                                title: list[i].fullName,
                                subtitle: [list[i].position, list[i].department].where((e) => e.isNotEmpty).join(' · '),
                                trailing: ConstrainedBox(
                                  constraints: const BoxConstraints(maxWidth: 120),
                                  child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.end, children: [
                                    FittedBox(fit: BoxFit.scaleDown, child: Text(context.money(list[i].salaryMinor), style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 14))),
                                    if (list[i].status == EmployeeStatus.inactive) Padding(padding: const EdgeInsets.only(top: 4), child: StatusPill(label: context.t('common.inactive'), color: g.textDim)),
                                  ]),
                                ),
                              ),
                            ),
                          ),
                        ),
                    ],
                  ),
          ),
        ],
      ),
    );
  }
}
