<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatAttachment extends Model
{
    protected $guarded = [];

    // Chat Relationship
    public function chat()
    {
        return $this->belongsTo(Chat::class);
    }

    // User Relationship
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
