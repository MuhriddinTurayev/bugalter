<?php

namespace App\Support;

/** Spreadsheet columns of the contractor export/import, with header lookup in every supported language. */
final class ContractorColumns
{
    public const FIELDS = ['name', 'stir', 'type', 'phone', 'email', 'address', 'bank_name', 'bank_account', 'mfo'];

    /** @return list<string> localized headings for the export */
    public static function headings(): array
    {
        return array_map(fn (string $field) => __('ui.contractors.fields.'.$field), self::FIELDS);
    }

    /** Maps a spreadsheet heading (field key or a label in any supported language) to a field key. */
    public static function fieldFor(string $heading): ?string
    {
        $needle = mb_strtolower(trim($heading));

        foreach (self::FIELDS as $field) {
            if ($needle === $field) {
                return $field;
            }

            foreach (array_keys(Locale::supported()) as $locale) {
                if ($needle === mb_strtolower(__('ui.contractors.fields.'.$field, [], $locale))) {
                    return $field;
                }
            }
        }

        return null;
    }
}
