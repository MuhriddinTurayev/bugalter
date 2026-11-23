import 'package:flutter/material.dart';

import '../../../data/models.dart';

/// An icon for a category: system categories have a fixed one, custom ones a generic one by type.
IconData categoryIcon(Category? c, {TxType? fallbackType}) {
  switch (c?.key) {
    case 'sales':
      return Icons.shopping_bag_rounded;
    case 'services':
      return Icons.handyman_rounded;
    case 'other_income':
      return Icons.add_card_rounded;
    case 'salary':
      return Icons.groups_rounded;
    case 'rent':
      return Icons.apartment_rounded;
    case 'utilities':
      return Icons.bolt_rounded;
    case 'supplies':
      return Icons.inventory_2_rounded;
    case 'transport':
      return Icons.local_shipping_rounded;
    case 'marketing':
      return Icons.campaign_rounded;
    case 'taxes':
      return Icons.receipt_long_rounded;
    case 'bank_fees':
      return Icons.account_balance_rounded;
    case 'other_expense':
      return Icons.more_horiz_rounded;
  }
  final type = c?.type ?? fallbackType;
  return type == TxType.income ? Icons.south_west_rounded : Icons.north_east_rounded;
}
