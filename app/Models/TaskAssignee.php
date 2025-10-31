<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskAssignee extends Model
{
    protected $guarded = [];

    public function task()
    {
        return $this->belongsTo(ProjectTask::class, 'task_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'employee_id');
    }
}
