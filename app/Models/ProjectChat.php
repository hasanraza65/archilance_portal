<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectChat extends Model
{
    protected $guarded = [];

    public function attachments()
    {
        return $this->hasMany(ProjectChatAttachment::class, 'chat_id');
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
