<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The current salary arrangement for one employee. History lives in
 * SalaryRevision — never mutate this without writing a revision alongside it
 * (SalaryController::setSalary does both inside one transaction).
 */
class SalaryProfile extends Model
{
    protected $guarded = [];

    protected $casts = [
        'monthly_salary' => 'decimal:2',
        'effective_from' => 'date:Y-m-d',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function revisions()
    {
        return $this->hasMany(SalaryRevision::class, 'user_id', 'user_id')->latest('effective_from');
    }

    /** Only Active profiles are swept into a generated payroll run. */
    public function scopePayable($query)
    {
        return $query->where('status', 'Active')->where('monthly_salary', '>', 0);
    }
}
