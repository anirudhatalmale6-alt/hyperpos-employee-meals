<?php

namespace EmployeeMeals\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Department extends Model
{
    protected $table = 'em_departments';

    protected $fillable = ['em_company_id', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<MealCompany, Department> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(MealCompany::class, 'em_company_id');
    }
}
