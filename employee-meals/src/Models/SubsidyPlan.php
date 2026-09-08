<?php

namespace EmployeeMeals\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How a meal is paid for. The daily value lives here as DATA - the client was
 * explicit that R38 must never be hard-coded into the software.
 */
class SubsidyPlan extends Model
{
    protected $table = 'em_subsidy_plans';

    /** Employer pays the whole meal. */
    public const MODE_FULL = 'full';
    /** Employer pays up to the daily value, employee pays the rest now. */
    public const MODE_PART = 'part';
    /** Employee portion accrues; payroll clears it monthly. */
    public const MODE_SALARY = 'salary';
    /** The whole line is charged to the company account. */
    public const MODE_COMPANY = 'company';

    protected $fillable = [
        'em_company_id', 'name', 'mode', 'daily_value', 'meals_per_day',
        'qualifying_days', 'on_exhausted', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'daily_value' => 'decimal:4',
            'meals_per_day' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<MealCompany, SubsidyPlan> */
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

    /** Does the employee settle their own portion at the till, or later? */
    public function settlesLater(): bool
    {
        return in_array($this->mode, [self::MODE_SALARY, self::MODE_COMPANY], true);
    }
}
