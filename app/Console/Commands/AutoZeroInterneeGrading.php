<?php

namespace App\Console\Commands;

use App\Models\InterneeRating;
use App\Models\LeaveRequest;
use App\Models\ProjectTask;
use App\Models\TaskAssignee;
use App\Models\User;
use App\Models\WorkSession;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Auto-grades an internee 0/0 for any working day they did NOT work.
 *
 * The manager grading popup only appears for days an internee actually tracked
 * time, so "no work at all" days would otherwise never get a rating. This fills
 * those gaps — including retroactively, back to the day the internee was first
 * assigned to a task.
 *
 * A day is skipped when ANY of these is true:
 *   - a work session covers it (they worked)
 *   - a rating already exists for that internee on that date (no duplicates —
 *     checked per internee+date, not per task, so a manager's manual grade on a
 *     different task still blocks an auto row)
 *   - it's a weekend            (override with --include-weekends)
 *   - an approved leave covers it (override with --include-leaves)
 *   - it's before their first task assignment / joining date, or it's today
 *     (today isn't over yet — only fully-elapsed days are graded)
 *
 * Options:
 *   --days=N          only look back N days instead of all history
 *   --internee=ID     restrict to one internee
 *   --date=Y-m-d      grade one specific day only
 *   --dry-run         report what WOULD be created, write nothing
 *   --include-weekends / --include-leaves   disable those skips
 */
class AutoZeroInterneeGrading extends Command
{
    protected $signature = 'internee:auto-zero-grading
                            {--days= : Only look back this many days}
                            {--internee= : Only this internee id}
                            {--date= : Only this date (Y-m-d)}
                            {--dry-run : Show what would be created without writing}
                            {--include-weekends : Also grade Saturdays and Sundays}
                            {--include-leaves : Also grade days covered by approved leave}';

    protected $description = 'Auto-create a 0/0 grading for every working day an internee did not work.';

    public function handle()
    {
        // The whole run is safe to retry from scratch: firstOrCreate() + the
        // unique (manager, internee, task, date) index mean a retry can never
        // duplicate rows a prior attempt already wrote. This exists because
        // this host's DB connection limit gets hit around the times this
        // command is scheduled, which was previously killing the whole run.
        return retry(6, function () {
            $this->runOnce();
        }, function (int $attempt) {
            $this->warn("Retrying after DB error (attempt {$attempt})...");
            return min($attempt * 15000, 60000); // 15s, 30s, 45s, 60s, 60s
        }, function (\Throwable $e) {
            return $e instanceof \Illuminate\Database\QueryException
                || $e instanceof \PDOException;
        }) ?? self::SUCCESS;
    }

    private function runOnce()
    {
        $dryRun = (bool) $this->option('dry-run');
        $skipWeekends = !$this->option('include-weekends');
        $skipLeaves = !$this->option('include-leaves');

        // Only fully-elapsed days: today is still in progress.
        $lastDay = Carbon::yesterday()->startOfDay();
        if ($this->option('date')) {
            $lastDay = Carbon::parse($this->option('date'))->startOfDay();
        }

        $earliestAllowed = null;
        if ($this->option('days')) {
            $earliestAllowed = $lastDay->copy()->subDays(max(0, (int) $this->option('days') - 1));
        }
        if ($this->option('date')) {
            $earliestAllowed = $lastDay->copy();
        }

        $internees = User::where('employee_type', 'Internee')
            ->whereNotNull('internee_manager_id')
            ->when($this->option('internee'), fn($q) => $q->where('id', (int) $this->option('internee')))
            ->get(['id', 'name', 'internee_manager_id', 'joining_date']);

        if ($internees->isEmpty()) {
            $this->info('No internees with an assigned manager. Nothing to do.');
            return self::SUCCESS;
        }

        $interneeIds = $internees->pluck('id')->all();

        // ---- Bulk-load everything up front (a handful of queries, no N+1) ----

        // First task assignment per internee + the tasks they can be graded on.
        $assignments = TaskAssignee::whereIn('employee_id', $interneeIds)
            ->orderBy('created_at')
            ->get(['employee_id', 'task_id', 'created_at']);

        $taskIds = $assignments->pluck('task_id')->unique()->values()->all();
        $tasksById = ProjectTask::whereIn('id', $taskIds)
            ->get(['id', 'parent_task_id', 'task_title'])
            ->keyBy('id');

        // Worked days: expand every session across the days it spans.
        $workedDays = [];
        WorkSession::whereIn('user_id', $interneeIds)
            ->whereNotNull('start_date')
            ->get(['user_id', 'start_date', 'end_date'])
            ->each(function ($s) use (&$workedDays) {
                try {
                    $from = Carbon::parse($s->start_date)->startOfDay();
                    $to = $s->end_date ? Carbon::parse($s->end_date)->startOfDay() : $from->copy();
                } catch (\Throwable $e) {
                    return;
                }
                if ($to->lt($from)) $to = $from->copy();
                for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                    $workedDays[$s->user_id][$d->toDateString()] = true;
                }
            });

        // Days already graded (ANY task) — this is the duplicate guard.
        $gradedDays = [];
        InterneeRating::whereIn('internee_id', $interneeIds)
            ->get(['internee_id', 'rating_date'])
            ->each(function ($r) use (&$gradedDays) {
                $gradedDays[$r->internee_id][Carbon::parse($r->rating_date)->toDateString()] = true;
            });

        // Approved leave days.
        $leaveDays = [];
        if ($skipLeaves) {
            LeaveRequest::whereIn('user_id', $interneeIds)
                ->where('status', 'Approved')
                ->get(['user_id', 'start_date', 'end_date'])
                ->each(function ($l) use (&$leaveDays) {
                    try {
                        $from = Carbon::parse($l->start_date)->startOfDay();
                        $to = Carbon::parse($l->end_date)->startOfDay();
                    } catch (\Throwable $e) {
                        return;
                    }
                    if ($to->lt($from)) $to = $from->copy();
                    for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                        $leaveDays[$l->user_id][$d->toDateString()] = true;
                    }
                });
        }

        $assignmentsByInternee = $assignments->groupBy('employee_id');
        $rootCache = [];
        $created = 0;
        $skipped = 0;

        foreach ($internees as $internee) {
            $mine = $assignmentsByInternee->get($internee->id, collect());
            if ($mine->isEmpty()) {
                continue; // never assigned to anything — nothing to grade against
            }

            // Start from their first assignment, but never before joining date.
            $start = Carbon::parse($mine->first()->created_at)->startOfDay();
            if ($internee->joining_date) {
                try {
                    $joined = Carbon::parse($internee->joining_date)->startOfDay();
                    if ($joined->gt($start)) $start = $joined;
                } catch (\Throwable $e) {
                    // unparseable joining date — keep the assignment date
                }
            }
            if ($earliestAllowed && $earliestAllowed->gt($start)) {
                $start = $earliestAllowed->copy();
            }
            if ($start->gt($lastDay)) {
                continue;
            }

            for ($day = $start->copy(); $day->lte($lastDay); $day->addDay()) {
                $key = $day->toDateString();

                if ($skipWeekends && $day->isWeekend()) { continue; }
                if (isset($workedDays[$internee->id][$key])) { continue; }
                if (isset($gradedDays[$internee->id][$key])) { $skipped++; continue; }
                if ($skipLeaves && isset($leaveDays[$internee->id][$key])) { continue; }

                // Grade against the most recent ROOT task assigned on/before this day.
                $task = $this->taskForDay($mine, $day, $tasksById, $rootCache);
                if (!$task) { continue; }

                if ($dryRun) {
                    $this->line("  would grade 0/0 — {$internee->name} on {$key} (task #{$task->id} {$task->task_title})");
                    $created++;
                    continue;
                }

                // firstOrCreate honours the unique (manager, internee, task, date)
                // index, so a concurrent run can never double-insert.
                $rating = InterneeRating::firstOrCreate(
                    [
                        'manager_id' => $internee->internee_manager_id,
                        'internee_id' => $internee->id,
                        'task_id' => $task->id,
                        'rating_date' => $key,
                    ],
                    [
                        'did_not_work' => false, // false so the zeros count toward their average
                        'technical_accuracy' => 0,
                        'learning_improvement' => 0,
                        'ownership_initiative' => 0,
                        'communication_professionalism' => 0,
                        'overall_recommendation' => 0,
                        'comments' => 'Auto-generated: no work was tracked on this day.',
                    ]
                );

                if ($rating->wasRecentlyCreated) {
                    $created++;
                    // Mark it so later days in this same run see it too.
                    $gradedDays[$internee->id][$key] = true;
                } else {
                    $skipped++;
                }
            }
        }

        $verb = $dryRun ? 'would be created' : 'created';
        $this->info("Auto 0/0 gradings {$verb}: {$created}. Already graded (skipped): {$skipped}.");

        return self::SUCCESS;
    }

    /**
     * The root task to attach a missed day to: the most recently assigned task
     * on/before that day, rolled up to its top-level parent (ratings always sit
     * on the root task, matching InterneeRatingController).
     */
    private function taskForDay($assignments, Carbon $day, $tasksById, array &$rootCache)
    {
        $chosen = null;
        foreach ($assignments as $a) {
            try {
                $assignedOn = Carbon::parse($a->created_at)->startOfDay();
            } catch (\Throwable $e) {
                continue;
            }
            if ($assignedOn->lte($day)) {
                $chosen = $a; // ordered by created_at, so the last match is the newest
            }
        }
        if (!$chosen) {
            return null;
        }

        if (array_key_exists($chosen->task_id, $rootCache)) {
            return $rootCache[$chosen->task_id];
        }

        $current = $tasksById->get($chosen->task_id);
        $depth = 0;
        while ($current && $current->parent_task_id && $depth < 10) {
            $parent = $tasksById->get($current->parent_task_id);
            if (!$parent) {
                $parent = ProjectTask::select('id', 'parent_task_id', 'task_title')->find($current->parent_task_id);
                if ($parent) $tasksById->put($parent->id, $parent);
            }
            if (!$parent) break;
            $current = $parent;
            $depth++;
        }

        $rootCache[$chosen->task_id] = $current;
        return $current;
    }
}
