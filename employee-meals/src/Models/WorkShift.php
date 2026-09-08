<?php

namespace EmployeeMeals\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Named WorkShift, not Shift: Hyper POS already has a `Shift` model for
 * cashier till shifts and the two must not be confused.
 */
class WorkShift extends Model
{
    protected $table = 'em_shifts';

    protected $fillable = [
        'em_company_id', 'name', 'starts_at', 'ends_at', 'qualifying_days', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<MealCompany, WorkShift> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(MealCompany::class, 'em_company_id');
    }

    /** @return list<int> 1=Mon .. 7=Sun */
    public function qualifyingDays(): array
    {
        return array_values(array_filter(array_map(
            'intval',
            explode(',', (string) $this->qualifying_days)
        )));
    }
}
