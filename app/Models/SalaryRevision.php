<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One entry in an employee's salary history. Append-only: a mistake is fixed by
 * adding a "Correction" row, never by editing or deleting an existing one.
 */
class SalaryRevision extends Model
{
    protected $guarded = [];

    protected $casts = [
        'previous_amount' => 'decimal:2',
        'new_amount'      => 'decimal:2',
        'change_amount'   => 'decimal:2',
        'effective_from'  => 'date:Y-m-d',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
