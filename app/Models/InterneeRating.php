<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InterneeRating extends Model
{
    protected $guarded = [];

    protected $casts = [
        'rating_date' => 'date:Y-m-d',
        'did_not_work' => 'boolean',
        'technical_accuracy' => 'integer',
        'learning_improvement' => 'integer',
        'ownership_initiative' => 'integer',
        'communication_professionalism' => 'integer',
        'overall_recommendation' => 'integer',
    ];

    protected $appends = ['average_rating'];

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function internee()
    {
        return $this->belongsTo(User::class, 'internee_id');
    }

    public function task()
    {
        return $this->belongsTo(ProjectTask::class, 'task_id');
    }

    public function getAverageRatingAttribute()
    {
        $fields = [
            $this->technical_accuracy,
            $this->learning_improvement,
            $this->ownership_initiative,
            $this->communication_professionalism,
            $this->overall_recommendation,
        ];

        if ($this->did_not_work || in_array(null, $fields, true)) {
            return null;
        }

        return round(array_sum($fields) / count($fields), 2);
    }
}
