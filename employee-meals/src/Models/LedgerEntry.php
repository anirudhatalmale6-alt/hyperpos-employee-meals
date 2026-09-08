<?php

namespace EmployeeMeals\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The record of truth for one subsidised transaction.
 *
 * A Hyper discount reduces a total but never records WHO OWED WHAT. This row
 * does: gross, the employer's share, the employee's share, and how the
 * employee's share was settled. Every figure the client asked for in his
 * reporting section is a query over this table.
 *
 * Company and department are SNAPSHOTS, not lookups - a transfer between
 * departments next month must not silently rewrite last month's report.
 */
class LedgerEntry extends Model
{
    protected $table = 'em_ledger_entries';

    public const SETTLE_CASH = 'cash';
    public const SETTLE_CARD = 'card';
    public const SETTLE_SALARY = 'salary';
    public const SETTLE_COMPANY = 'company';
    public const SETTLE_NONE = 'none';

    protected $fillable = [
        'em_employee_id', 'em_entitlement_id', 'em_company_id', 'em_department_id',
        'occurred_at', 'trade_date', 'sale_id', 'sale_reference',
        'gross_amount', 'subsidy_amount', 'employee_amount',
        'settle_method', 'is_settled', 'settled_at', 'settlement_ref', 'note',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'trade_date' => 'date',
            'gross_amount' => 'decimal:4',
            'subsidy_amount' => 'decimal:4',
            'employee_amount' => 'decimal:4',
            'is_settled' => 'boolean',
            'settled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employee, LedgerEntry> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'em_employee_id');
    }

    /** @return BelongsTo<Entitlement, LedgerEntry> */
    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(Entitlement::class, 'em_entitlement_id');
    }
}
