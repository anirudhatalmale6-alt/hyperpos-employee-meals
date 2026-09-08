<?php

namespace EmployeeMeals\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One captured sample. Four fingers, several touches each - the probe is
 * matched against every stored sample and the BEST score counts, because
 * averaging punishes one crooked press.
 */
class Fingerprint extends Model
{
    protected $table = 'em_fingerprints';

    public const FINGERS = ['right_thumb', 'right_index', 'left_thumb', 'left_index'];

    protected $fillable = [
        'em_employee_id', 'finger', 'sample_no', 'template', 'image',
        'quality', 'minutiae_count', 'enrolled_at',
    ];

    protected $hidden = ['template', 'image'];

    protected function casts(): array
    {
        return ['enrolled_at' => 'datetime'];
    }

    /** @return BelongsTo<Employee, Fingerprint> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'em_employee_id');
    }
}
