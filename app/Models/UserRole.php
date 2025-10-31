<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserRole extends Model
{
    use SoftDeletes;
    protected $guarded = [];

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'permission_roles');
    }
}
