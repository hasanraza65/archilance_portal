<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\ProjectTask;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Emails each employee a single digest of their tasks whose due date is
 * approaching (or overdue) and that are not yet completed. Idempotent: a task
 * is only included once per day per user (tracked via an in-app reminder row),
 * so it's safe even if the scheduler runs it more than once.
 */
class DueDateReminders extends Command
{
    protected $signature = 'notify:due-reminders {--days=2}';
    protected $description = 'Email employees a digest of their tasks with an approaching due date.';

    public function handle()
    {
        $days  = max(0, (int) $this->option('days'));
        $today = Carbon::today();
        $limit = $today->copy()->addDays($days);

        // Tasks due on/before the window end, not completed, that have a due date.
        $tasks = ProjectTask::query()
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', $limit)
            ->whereNotIn('task_status', ['Completed', 'Done'])
            ->with(['assignees.user', 'project'])
            ->get();

        // Group by employee (skip customers and users without an email).
        $byEmployee = [];
        foreach ($tasks as $task) {
            foreach ($task->assignees as $assignee) {
                $u = $assignee->user;
                if (!$u || empty($u->email)) {
                    continue;
                }
                if (in_array((int) $u->user_role, [4, 5], true)) {
                    continue; // never email customers
                }
                $byEmployee[$u->id]['user'] = $u;
                $byEmployee[$u->id]['tasks'][] = $task;
            }
        }

        $emailsSent = 0;

        foreach ($byEmployee as $uid => $bucket) {
            $user = $bucket['user'];

            // Only tasks not already reminded to this user today.
            $freshTasks = [];
            foreach ($bucket['tasks'] as $task) {
                $already = Notification::where('user_id', $uid)
                    ->where('notification_type', 'task_due_reminder')
                    ->where('project_id', $task->id)
                    ->whereDate('created_at', $today)
                    ->exists();
                if (!$already) {
                    $freshTasks[] = $task;
                }
            }

            if (empty($freshTasks)) {
                continue;
            }

            // In-app reminder per task (also acts as the per-day dedupe marker).
            foreach ($freshTasks as $task) {
                insertNotificationWithNature(
                    $uid,
                    $uid,
                    'task_due_reminder',
                    'Reminder: "' . $task->task_title . '" is due ' . $this->dueLabel($task->due_date),
                    'warning',
                    $task->id
                );
            }

            // Build + send the digest email.
            $items = [];
            foreach ($freshTasks as $task) {
                $projectName = optional($task->project)->project_name;
                $items[] = [
                    'title' => $task->task_title,
                    'meta'  => trim(($projectName ? $projectName . ' • ' : '') . 'Due ' . $this->dueLabel($task->due_date)),
                    'badge' => ['text' => $task->task_status ?: 'Backlog', 'kind' => 'status'],
                    'url'   => frontendUrl('/project/' . $task->id),
                ];
            }

            $count = count($items);
            sendNotificationEmail(
                $user,
                '⏰ You have ' . $count . ' task' . ($count > 1 ? 's' : '') . ' due soon — Archilance',
                [
                    'emoji'   => '⏰',
                    'heading' => 'Tasks due soon',
                    'intro'   => 'Here ' . ($count > 1 ? 'are' : 'is') . ' your ' . $count . ' task' . ($count > 1 ? 's' : '')
                        . ' with an approaching due date. Please make sure ' . ($count > 1 ? 'they’re' : 'it’s') . ' on track.',
                    'items'   => $items,
                    'cta'     => ['text' => 'View my tasks', 'url' => frontendUrl('/members')],
                    'signoff' => 'Tip: keep your task statuses up to date as you make progress.',
                ],
                'due_reminder'
            );
            $emailsSent++;
        }

        $this->info("Due-date reminders processed. Digest emails sent: {$emailsSent}");
        return self::SUCCESS;
    }

    private function dueLabel($dueDate): string
    {
        $due   = Carbon::parse($dueDate)->startOfDay();
        $today = Carbon::today();
        $diff  = (int) $today->diffInDays($due, false); // negative = overdue

        if ($diff === 0) {
            return 'today (' . $due->format('M j') . ')';
        }
        if ($diff === 1) {
            return 'tomorrow (' . $due->format('M j') . ')';
        }
        if ($diff < 0) {
            $d = abs($diff);
            return 'overdue by ' . $d . ' day' . ($d > 1 ? 's' : '') . ' (' . $due->format('M j') . ')';
        }
        return 'in ' . $diff . ' days (' . $due->format('M j') . ')';
    }
}
