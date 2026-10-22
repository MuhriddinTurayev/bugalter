<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

#[Fillable(['key', 'value', 'updated_at'])]
class PlatformSetting extends Model
{
    use CentralConnection;

    /** The table has only updated_at, which write() sets itself. */
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    /** @var array<string, string> */
    protected $casts = ['value' => 'array', 'updated_at' => 'datetime'];

    public static function read(string $key, mixed $default = null): mixed
    {
        return static::query()->find($key)->value ?? $default;
    }

    public static function write(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_at' => now()]);
    }
}
