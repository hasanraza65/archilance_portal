<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectBrief extends Model
{
    protected $guarded = [];

    public function attachments()
    {
        return $this->hasMany(BriefAttachment::class, 'brief_id');
    }
}
