<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    protected $guarded = [];

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function tasks()
    {
        return $this->hasMany(ProjectTask::class, 'project_id')
            ->whereNull('parent_task_id');
    }

    public function allTasks()
    {
        return $this->hasMany(ProjectTask::class, 'project_id');
    }

    public function allBriefs()
    {
        return $this->hasMany(ProjectBrief::class, 'project_id');
    }

    public function projectAssignees()
    {
        return $this->hasMany(ProjectAssignee::class, 'project_id');
    }

    public function allNotes()
    {
        return $this->hasMany(Note::class, 'project_id')
                    ->where('type', 'project');
    }
}
