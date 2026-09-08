<?php

namespace EmployeeMeals\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** The employer whose staff eat here. Cipla initially. */
class MealCompany extends Model
{
    protected $table = 'em_companies';

    protected $fillable = ['name', 'code', 'settlement', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<Department, MealCompany> */
    public function departments(): HasMany
    {
        return $this->hasMany(Department::class, 'em_company_id');
    }

    /** @return HasMany<WorkShift, MealCompany> */
    public function shifts(): HasMany
    {
        return $this->hasMany(WorkShift::class, 'em_company_id');
    }

    /** @return HasMany<SubsidyPlan, MealCompany> */
    public function subsidyPlans(): HasMany
    {
        return $this->hasMany(SubsidyPlan::class, 'em_company_id');
    }

    /** @return HasMany<Employee, MealCompany> */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'em_company_id');
    }
}
