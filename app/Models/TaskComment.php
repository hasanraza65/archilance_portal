<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskComment extends Model
{
    protected $guarded = [];

    public function task()
    {
        return $this->belongsTo(ProjectTask::class, 'task_id');
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function replies()
    {
        return $this->hasMany(TaskComment::class, 'reply_to');
    }

    public function parent()
    {
        return $this->belongsTo(TaskComment::class, 'reply_to');
    }

    public function readStatuses()
    {
        return $this->hasMany(TaskCommentReadStatus::class, 'comment_id');
    }

    public function isReadBy($userId)
    {
        return $this->readStatuses()->where('receiver_id', $userId)->value('is_read') ?? false;
    }

    public function commentAttachments()
    {
        return $this->hasMany(TaskCommentAttachment::class, 'comment_id');
    }
}
