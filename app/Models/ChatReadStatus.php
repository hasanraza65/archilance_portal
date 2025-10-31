<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatReadStatus extends Model
{
    protected $guarded = [];

    // Chat (Message) Relationship
    public function message()
    {
        return $this->belongsTo(Chat::class, 'message_id');
    }

    // Receiver Relationship
    public function receiver()
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }
}
