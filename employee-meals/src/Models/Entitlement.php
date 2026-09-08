<?php

namespace EmployeeMeals\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Today's subsidised meal for one employee.
 *
 * There is exactly one row per employee per day, enforced by a unique key in
 * the database rather than by a check in application code. That is what makes
 * the double-claim block hold under a double-click, a network retry, or two
 * tills scanning the same card in the same second.
 */
class Entitlement extends Model
{
    protected $table = 'em_entitlements';

    public const AVAILABLE = 'available';
    public const RESERVED = 'reserved';
    public const CONSUMED = 'consumed';
    public const RELEASED = 'released';
    public const REVERSED = 'reversed';

    protected $fillable = [
        'em_employee_id', 'em_company_id', 'em_subsidy_plan_id', 'entitlement_date',
        'value', 'status', 'reserved_at', 'consumed_at', 'released_at',
        'sale_id', 'sale_reference',
    ];

    protected function casts(): array
    {
        return [
            'entitlement_date' => 'date',
            'value' => 'decimal:4',
            'reserved_at' => 'datetime',
            'consumed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employee, Entitlement> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'em_employee_id');
    }

    public function isClaimable(): bool
    {
        return in_array($this->status, [self::AVAILABLE, self::RESERVED], true);
    }
}
