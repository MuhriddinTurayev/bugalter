<?php

namespace App\Services\Platform;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/** Size of each company's database in bytes (MySQL only; other drivers report nothing). */
class CompanyDatabaseSize
{
    /**
     * @param  iterable<Tenant>  $tenants
     * @return array<string, int> tenant id => bytes
     */
    public function forTenants(iterable $tenants): array
    {
        $tenants = collect($tenants);
        $connection = DB::connection(config('tenancy.database.central_connection'));

        if ($tenants->isEmpty() || ! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return [];
        }

        $names = $tenants->mapWithKeys(fn (Tenant $t) => [$t->database()->getName() => $t->id]);

        $rows = $connection->table('information_schema.tables')
            ->selectRaw('table_schema as db, sum(data_length + index_length) as bytes')
            ->whereIn('table_schema', $names->keys()->all())
            ->groupBy('table_schema')
            ->get();

        return $rows->mapWithKeys(fn ($row) => [$names[$row->db] => (int) $row->bytes])->all();
    }

    public static function format(?int $bytes): string
    {
        if ($bytes === null) {
            return '—';
        }

        foreach (['B', 'KB', 'MB', 'GB'] as $i => $unit) {
            if ($bytes < 1024 ** ($i + 1) || $unit === 'GB') {
                return number_format($bytes / 1024 ** $i, $i === 0 ? 0 : 1, ',', ' ').' '.$unit;
            }
        }

        return (string) $bytes;
    }
}
