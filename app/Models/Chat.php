<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Chat extends Model
{   

    use SoftDeletes;

    protected $dates = ['deleted_at'];
    protected $guarded = [];

    // Sender Relationship
    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    // Receiver Relationship
    public function receiver()
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    // Attachments Relationship
    public function attachments()
    {
        return $this->hasMany(ChatAttachment::class);
    }

    // Reactions Relationship
    public function reactions()
    {
        return $this->hasMany(ChatReaction::class);
    }

    // Read Statuses Relationship
    public function readStatuses()
    {
        return $this->hasMany(ChatReadStatus::class, 'message_id');
    }

    public function parent()
    {
        return $this->belongsTo(Chat::class, 'reply_to');
    }
}
