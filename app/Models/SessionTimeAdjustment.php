<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SessionTimeAdjustment extends Model
{
    protected $fillable = ['session_id', 'start_time', 'end_time'];
    
    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
    ];

    public function session()
    {
        return $this->belongsTo(WorkSession::class);
    }
}
