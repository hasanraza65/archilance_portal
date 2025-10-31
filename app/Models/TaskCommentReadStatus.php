<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskCommentReadStatus extends Model
{
    protected $guarded = [];

     public function comment()
    {
        return $this->belongsTo(TaskComment::class, 'comment_id');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }
}
