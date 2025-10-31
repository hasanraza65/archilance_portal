<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectTask extends Model
{
    protected $guarded = [];

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function parentTask()
    {
        return $this->belongsTo(ProjectTask::class, 'parent_task_id');
    }

    public function subTasks()
    {
        return $this->hasMany(ProjectTask::class, 'parent_task_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignees()
    {
        return $this->hasMany(TaskAssignee::class, 'task_id');
    }

    public function comments()
    {
        return $this->hasMany(TaskComment::class, 'task_id');
    }

    public function attachments()
    {
        return $this->hasMany(TaskAttachment::class, 'task_id');
    }

    public function allBriefs()
    {
        return $this->hasMany(TaskBrief::class, 'task_id');
    }
}
