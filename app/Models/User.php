<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */

    protected $guarded = [];
    use SoftDeletes;


    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $appends = [
        'today_time',
        'week_time'
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }


    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function commentReadStatuses()
    {
        return $this->hasMany(TaskCommentReadStatus::class, 'receiver_id');
    }

    // Projects assigned to the user
    // Many-to-many pivot for projects
    public function assignedProjects()
    {
        return $this->belongsToMany(Project::class, 'project_assignees', 'employee_id', 'project_id');
    }

    // Many-to-many pivot for tasks (main + sub)
    public function assignedTasks()
    {
        return $this->belongsToMany(ProjectTask::class, 'task_assignees', 'employee_id', 'task_id');
    }


    public function workSessions()
    {
        return $this->hasMany(WorkSession::class);
    }


   public function calculateWorkedTime($startDate, $endDate)
{
    $sessions = WorkSession::where('user_id', $this->id)
        ->whereDate('start_date', '<=', $endDate)
        ->where(function ($q) use ($startDate) {
            $q->whereDate('end_date', '>=', $startDate)
              ->orWhereNull('end_date');
        })
        ->get();

    $totalSeconds = 0;

    $filterDates = [];
    $currentDate = Carbon::parse($startDate);
    $endDateObj = Carbon::parse($endDate);

    while ($currentDate->lte($endDateObj)) {
        $filterDates[] = $currentDate->toDateString();
        $currentDate->addDay();
    }

    foreach ($sessions as $session) {
        $sessionStart = Carbon::parse($session->start_date . ' ' . $session->start_time);
        
        $sessionEnd = $session->end_time
            ? Carbon::parse(($session->end_date ?? $session->start_date) . ' ' . $session->end_time)
            : now();

        $sessionDuration = 0;

        foreach ($filterDates as $date) {
            $dayStart = Carbon::parse($date)->startOfDay();
            $dayEnd = Carbon::parse($date)->endOfDay();

            // FIX: Use Carbon's max() and min() methods instead of PHP's
            $workStart = $sessionStart->max($dayStart);
            $workEnd = $sessionEnd->min($dayEnd);

            if ($workStart->lt($workEnd)) {
                $sessionDuration += $workStart->diffInSeconds($workEnd); // FIX: Swap order to always get positive
            }
        }

        // Adjustments
        $adjustmentSeconds = 0;
        $adjustments = \DB::table('session_time_adjustments')
            ->where('session_id', $session->id)
            ->get();

        foreach ($adjustments as $adj) {
            if (!$adj->start_time || !$adj->end_time) continue;

            $adjStart = Carbon::parse($adj->start_time);
            $adjEnd = Carbon::parse($adj->end_time);

            foreach ($filterDates as $date) {
                $dayStart = Carbon::parse($date)->startOfDay();
                $dayEnd = Carbon::parse($date)->endOfDay();

                // FIX: Use Carbon's max() and min()
                $start = $adjStart->max($dayStart);
                $end = $adjEnd->min($dayEnd);

                if ($start->lt($end)) {
                    $adjustmentSeconds += $start->diffInSeconds($end); // FIX: Correct order
                }
            }
        }

        $netSeconds = $sessionDuration - $adjustmentSeconds;
        if ($netSeconds > 0) {
            $totalSeconds += $netSeconds;
        }
    }

    return $totalSeconds;
}



    public function formatSeconds($seconds)
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        return "{$hours}h {$minutes}m";
    }


    public function getTodayTimeAttribute()
    {
        $start = now()->toDateString();
        $end = now()->toDateString();
    
        return $this->formatSeconds(
            $this->calculateWorkedTime($start, $end)
        );
    }
    
    public function getWeekTimeAttribute()
    {
        $start = now()->startOfWeek()->toDateString();
        $end = now()->endOfWeek()->toDateString();
        
      
        
        return $this->formatSeconds(
            $this->calculateWorkedTime($start, $end)
        );
    }


}
