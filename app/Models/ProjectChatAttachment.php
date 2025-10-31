<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectChatAttachment extends Model
{
    protected $guarded = [];

    public function chat()
    {
        return $this->belongsTo(ProjectChat::class, 'chat_id');
    }
}
