<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskBrief extends Model
{
    protected $guarded = [];

    public function attachments()
    {
        return $this->hasMany(BriefAttachment::class, 'brief_id');
    }
}
