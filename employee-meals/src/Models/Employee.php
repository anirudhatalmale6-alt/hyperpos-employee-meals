<?php

namespace EmployeeMeals\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One employee = one record.
 *
 * The card number and the fingerprints are two CREDENTIALS belonging to this
 * person, not two systems. Both resolve here.
 */
class Employee extends Model
{
    use SoftDeletes;

    protected $table = 'em_employees';

    protected $fillable = [
        'em_company_id', 'em_department_id', 'em_shift_id', 'em_subsidy_plan_id',
        'customer_id', 'employee_no', 'first_name', 'last_name', 'photo_path',
        'card_number', 'is_active', 'meal_entitled', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'meal_entitled' => 'boolean',
        ];
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /** @return BelongsTo<MealCompany, Employee> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(MealCompany::class, 'em_company_id');
    }

    /** @return BelongsTo<Department, Employee> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'em_department_id');
    }

    /** @return BelongsTo<WorkShift, Employee> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(WorkShift::class, 'em_shift_id');
    }

    /** @return BelongsTo<SubsidyPlan, Employee> */
    public function subsidyPlan(): BelongsTo
    {
        return $this->belongsTo(SubsidyPlan::class, 'em_subsidy_plan_id');
    }

    /** @return HasMany<Fingerprint, Employee> */
    public function fingerprints(): HasMany
    {
        return $this->hasMany(Fingerprint::class, 'em_employee_id');
    }

    /** @return HasMany<Entitlement, Employee> */
    public function entitlements(): HasMany
    {
        return $this->hasMany(Entitlement::class, 'em_employee_id');
    }

    /** @return HasMany<LedgerEntry, Employee> */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'em_employee_id');
    }

    /**
     * The linked Hyper customer, resolved by id rather than by an Eloquent
     * relation so this plugin never holds a hard reference to a core model
     * that a future Hyper release could move or rename.
     */
    public function hyperCustomer(): ?object
    {
        if ($this->customer_id === null) {
            return null;
        }

        return \Illuminate\Support\Facades\DB::table('customers')
            ->where('id', $this->customer_id)
            ->first();
    }

    /** @param Builder<Employee> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Free-text search over the fields a canteen supervisor would actually
     * type: name, employee number or the card they are holding.
     *
     * @param Builder<Employee> $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);
        if ($term === '') {
            return;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        $query->where(function (Builder $q) use ($like) {
            $q->where('employee_no', 'like', $like)
                ->orWhere('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('card_number', 'like', $like)
                ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$like]);
        });
    }
}
