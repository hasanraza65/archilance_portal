<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;
use App\Traits\CalculatesIdleTime;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, CalculatesIdleTime;

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

    // NOTE: today_time / week_time are intentionally NOT globally appended.
    // Each accessor runs work_session queries, so appending them on every User
    // serialization (projects, tasks, members, calendar, chat, comments, …)
    // caused a heavy N+1. They are appended explicitly only where needed
    // (the employee tracking list — UserManagementController@index for role 3).
    protected $appends = [];

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
            // { category_key: bool } map of email notification opt-outs.
            'email_notification_preferences' => 'array',
            // 0 = employment contract not yet accepted, 1 = accepted (login allowed).
            'contract_status' => 'integer',
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

    // The manager assigned to this intern (only meaningful when employee_type === 'Internee')
    public function internManager()
    {
        return $this->belongsTo(User::class, 'internee_manager_id');
    }

    // Interns managed by this user
    public function internees()
    {
        return $this->hasMany(User::class, 'internee_manager_id');
    }

    // Ratings this user gave as a manager
    public function interneeRatingsGiven()
    {
        return $this->hasMany(InterneeRating::class, 'manager_id');
    }

    // Ratings this user received as an intern
    public function interneeRatingsReceived()
    {
        return $this->hasMany(InterneeRating::class, 'internee_id');
    }


   /**
    * Worked seconds between two dates.
    *
    * $preloadedSessions / $preloadedAdjustments let a caller hand in data it has
    * already fetched in BULK (see preloadWorkedTimes below), which removes the
    * per-user / per-session N+1 that made the employee list slow. Both are
    * optional — when omitted the method queries exactly as before, so every
    * existing caller is unaffected.
    *
    * Passing a SUPERSET of sessions is safe: the per-day $filterDates loop below
    * only counts the portion of each session that falls inside [$startDate,$endDate],
    * so sessions outside the range contribute zero.
    *
    * @param  \Illuminate\Support\Collection|null  $preloadedSessions      sessions for THIS user
    * @param  \Illuminate\Support\Collection|null  $preloadedAdjustments   idle rows keyed by session_id
    */
   public function calculateWorkedTime($startDate, $endDate, $preloadedSessions = null, $preloadedAdjustments = null)
{
    $sessions = $preloadedSessions !== null
        ? $preloadedSessions
        : WorkSession::where('user_id', $this->id)
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

        // Adjustments (idle). Overlapping idle rows are MERGED first so the same minute is
        // never subtracted twice, and each interval is clamped to this session's own window
        // so a stale row can never remove more time than the session actually contains.
        $adjustmentSeconds = 0;
        $adjustments = $preloadedAdjustments !== null
            ? $preloadedAdjustments->get($session->id, collect())
            : \DB::table('session_time_adjustments')
                ->where('session_id', $session->id)
                ->get();

        foreach ($this->mergeIdleIntervals($adjustments) as $mergedInterval) {
            [$mergedStart, $mergedEnd] = $mergedInterval;

            // Clamp to the session bounds before the per-day split.
            $adjStart = $mergedStart->greaterThan($sessionStart) ? $mergedStart->copy() : $sessionStart->copy();
            $adjEnd = $mergedEnd->lessThan($sessionEnd) ? $mergedEnd->copy() : $sessionEnd->copy();

            if ($adjEnd->lte($adjStart)) {
                continue;
            }

            foreach ($filterDates as $date) {
                $dayStart = Carbon::parse($date)->startOfDay();
                $dayEnd = Carbon::parse($date)->endOfDay();

                // FIX: Use Carbon's max() and min()
                $start = $adjStart->max($dayStart);
                $end = $adjEnd->min($dayEnd);

                if ($start->lt($end)) {
                    $adjustmentSeconds += (int) abs($start->diffInSeconds($end));
                }
            }
        }

        $netSeconds = (int) max(0, $sessionDuration - $adjustmentSeconds);
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


    /**
     * Values filled in by preloadWorkedTimes() so the accessors below don't each
     * run their own queries. A plain property (not an attribute), so it is never
     * serialized into the API response.
     */
    protected $precomputedWorkedTimes = [];

    public function setPrecomputedWorkedTimes(array $values)
    {
        $this->precomputedWorkedTimes = $values;

        return $this;
    }

    /**
     * Fill today_time / week_time for a whole collection of users using a FIXED
     * number of queries (2) instead of the accessors' per-user, per-session
     * queries — the N+1 that made the employee list slow.
     *
     * Produces byte-identical values to the accessors (same algorithm, same
     * formatSeconds output); it only changes HOW the data is fetched.
     */
    public static function preloadWorkedTimes($users)
    {
        $users = collect($users);
        if ($users->isEmpty()) {
            return;
        }

        $userIds = $users->pluck('id')->filter()->unique()->values()->all();
        if (empty($userIds)) {
            return;
        }

        $today     = now()->toDateString();
        $weekStart = now()->startOfWeek()->toDateString();
        $weekEnd   = now()->endOfWeek()->toDateString();

        // Widest window we need (today always falls inside the current week).
        $rangeStart = min($weekStart, $today);
        $rangeEnd   = max($weekEnd, $today);

        // QUERY 1 — every session for all these users overlapping the window.
        $sessions = WorkSession::whereIn('user_id', $userIds)
            ->whereDate('start_date', '<=', $rangeEnd)
            ->where(function ($q) use ($rangeStart) {
                $q->whereDate('end_date', '>=', $rangeStart)
                    ->orWhereNull('end_date');
            })
            ->get();

        // QUERY 2 — every idle row for those sessions, grouped by session.
        $adjustmentsBySession = $sessions->isEmpty()
            ? collect()
            : \DB::table('session_time_adjustments')
                ->whereIn('session_id', $sessions->pluck('id')->all())
                ->get()
                ->groupBy('session_id');

        $sessionsByUser = $sessions->groupBy('user_id');

        foreach ($users as $user) {
            $userSessions = $sessionsByUser->get($user->id, collect());

            $user->setPrecomputedWorkedTimes([
                'today_time' => $user->formatSeconds(
                    $user->calculateWorkedTime($today, $today, $userSessions, $adjustmentsBySession)
                ),
                'week_time' => $user->formatSeconds(
                    $user->calculateWorkedTime($weekStart, $weekEnd, $userSessions, $adjustmentsBySession)
                ),
            ]);
        }
    }

    public function getTodayTimeAttribute()
    {
        if (array_key_exists('today_time', $this->precomputedWorkedTimes)) {
            return $this->precomputedWorkedTimes['today_time'];
        }

        $start = now()->toDateString();
        $end = now()->toDateString();

        return $this->formatSeconds(
            $this->calculateWorkedTime($start, $end)
        );
    }
    
    public function getWeekTimeAttribute()
    {
        if (array_key_exists('week_time', $this->precomputedWorkedTimes)) {
            return $this->precomputedWorkedTimes['week_time'];
        }

        $start = now()->startOfWeek()->toDateString();
        $end = now()->endOfWeek()->toDateString();
        
      
        
        return $this->formatSeconds(
            $this->calculateWorkedTime($start, $end)
        );
    }


}
