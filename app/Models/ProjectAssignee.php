<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectAssignee extends Model
{
    protected $guarded = [];

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'employee_id');
    }
}
