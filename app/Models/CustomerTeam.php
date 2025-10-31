<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerTeam extends Model
{
    protected $guarded = [];

    public function teamUser()
    {
        return $this->belongsTo(User::class, 'team_user_id');
    }

    public function customerUser()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }
}
