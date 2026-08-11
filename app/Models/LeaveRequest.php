<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveRequest extends Model
{
    protected $guarded = [];

   
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Whoever approved or rejected this request. `approved_by` is stamped for
     * both outcomes, so this is really "reviewed by" — read it together with
     * `status` to know which it was.
     */
    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
