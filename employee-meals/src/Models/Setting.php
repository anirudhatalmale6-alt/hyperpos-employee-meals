<?php

namespace EmployeeMeals\Models;

use Illuminate\Database\Eloquent\Model;

/** Operator-changeable values. Nothing here belongs in code. */
class Setting extends Model
{
    protected $table = 'em_settings';

    protected $fillable = ['em_company_id', 'skey', 'svalue'];

    public static function get(string $key, mixed $default = null, ?int $companyId = null): mixed
    {
        $row = static::query()
            ->where('skey', $key)
            ->where('em_company_id', $companyId)
            ->first();

        return $row?->svalue ?? $default;
    }

    public static function put(string $key, mixed $value, ?int $companyId = null): void
    {
        static::query()->updateOrCreate(
            ['skey' => $key, 'em_company_id' => $companyId],
            ['svalue' => is_scalar($value) ? (string) $value : json_encode($value)]
        );
    }
}
