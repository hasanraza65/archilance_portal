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

    public function allNotes()
    {
        return $this->hasMany(Note::class, 'project_id')
            ->where('type', 'task');
    }

    public function latestInternalComment()
    {
        return $this->hasOne(TaskComment::class, 'task_id')
            ->where('allowed_customer', 0)
            ->latestOfMany();
    }

    public function latestCustomerComment()
    {
        return $this->hasOne(TaskComment::class, 'task_id')
            ->where('allowed_customer', 1)
            ->latestOfMany();
    }

    // app/Models/ProjectTask.php

    public function pinnedInternalComments()
    {
        return $this->hasMany(TaskComment::class, 'task_id')
            ->where('allowed_customer', 0)
            ->where('is_pinned', 1)
            ->latest();
    }

    public function pinnedCustomerComments()
    {
        return $this->hasMany(TaskComment::class, 'task_id')
            ->where('allowed_customer', 1)
            ->where('is_pinned', 1)
            ->latest();
    }

    public function latestInternalComment()
    {
        return $this->hasOne(TaskComment::class, 'task_id')
                    ->where('allowed_customer', 0)
                    ->latestOfMany();
    }

    public function latestCustomerComment()
    {
        return $this->hasOne(TaskComment::class, 'task_id')
                    ->where('allowed_customer', 1)
                    ->latestOfMany();
    }
}
