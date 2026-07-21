<?php

use App\Models\Project;
use App\Models\ProjectAssignee;
use App\Models\TaskAssignee;
use App\Models\ProjectTask;
use Illuminate\Support\Facades\File;
use App\Models\Notification;
use App\Models\User;



if (!function_exists('public_storage_copy')) {
    function public_storage_copy($relativePath)
    {
        $source = storage_path('app/public/' . $relativePath);
        $destination = public_path('storage/' . $relativePath);

        if (File::exists($source)) {
            File::ensureDirectoryExists(dirname($destination));
            File::copy($source, $destination);
        }
    }
}


function statusChangedNotification($project_id, $status, $type)
{
    $from_user = User::find(Auth::user()->id);

    // Map status to notification nature
    $status_nature_map = [
        'In Progress'     => 'primary',
        'Backlog'         => 'warning',
        'In-house Review' => 'warning',
        'Awaiting Info'   => 'warning',
        'On Hold'         => 'danger',
        'Client Review'   => 'primary',
        'Completed'       => 'success',
    ];

    // Default nature if status not found
    $nature = $status_nature_map[$status] ?? 'primary';

    if ($type == "project_status_changed") {

        $projAssignees = ProjectAssignee::where('project_id', $project_id)->get();
        $project_detail = Project::find($project_id);

        foreach ($projAssignees as $assignee) {

            $user_data = User::find($assignee->employee_id);

            if ($user_data) {

                $message = $from_user->name . " has changed the status to " . $status . " of job " . $project_detail->project_name;

                // Pass nature to insertNotification
                insertNotificationWithNature($user_data->id, $from_user->id, $type, $message, $nature, $project_id);

                sendNotificationEmail($user_data, 'Status updated: ' . $project_detail->project_name . ' — Archilance', [
                    'emoji'   => '🔄',
                    'heading' => 'Status updated',
                    'intro'   => e($from_user->name) . ' changed the status of the job <strong>' . e($project_detail->project_name) . '</strong> to <strong>' . e($status) . '</strong>.',
                    'details' => [
                        ['label' => 'Job', 'value' => $project_detail->project_name],
                        ['label' => 'New status', 'badge' => ['text' => $status, 'kind' => 'status']],
                        ['label' => 'Changed by', 'value' => $from_user->name],
                    ],
                    'cta' => ['text' => 'View job', 'url' => frontendUrl('/jobs/' . $project_id)],
                ]);
            }
        }
    }

    if ($type == "task_status_changed") {

        $projAssignees = TaskAssignee::where('task_id', $project_id)->get();
        $project_detail = ProjectTask::find($project_id);

        foreach ($projAssignees as $assignee) {

            $user_data = User::find($assignee->employee_id);

            if ($user_data) {

                $message = $from_user->name . " has changed the status to " . $status . " of project/task " . $project_detail->task_title;

                // Pass nature to insertNotification
                insertNotificationWithNature($user_data->id, $from_user->id, $type, $message, $nature, $project_id);

                sendNotificationEmail($user_data, 'Status updated: ' . $project_detail->task_title . ' — Archilance', [
                    'emoji'   => '🔄',
                    'heading' => 'Status updated',
                    'intro'   => e($from_user->name) . ' changed the status of <strong>' . e($project_detail->task_title) . '</strong> to <strong>' . e($status) . '</strong>.',
                    'details' => [
                        ['label' => 'Task', 'value' => $project_detail->task_title],
                        ['label' => 'New status', 'badge' => ['text' => $status, 'kind' => 'status']],
                        ['label' => 'Changed by', 'value' => $from_user->name],
                    ],
                    'cta' => ['text' => 'View task', 'url' => frontendUrl('/project/' . $project_id)],
                ]);
            }
        }
    }
}


    function briefAddedNotification($project_id, $type){

        $from_user = User::find(Auth::user()->id);

        $nature = "primary";
        
        if ($type == "project_brief_added") {

            $projAssignees = ProjectAssignee::where('project_id', $project_id)->get();
            $project_detail = Project::find($project_id);

            foreach ($projAssignees as $assignee) {

                $user_data = User::find($assignee->employee_id);

                if ($user_data) {

                    $message = $from_user->name . " has added a brief for job " . $project_detail->project_name;

                    // Pass nature to insertNotification
                    insertNotificationWithNature($user_data->id, $from_user->id, $type, $message, $nature, $project_id);
                }
            }

        }


        if ($type == "task_brief_added") {

            $projAssignees = TaskAssignee::where('task_id', $project_id)->get();
            $project_detail = ProjectTask::find($project_id);

            foreach ($projAssignees as $assignee) {

                $user_data = User::find($assignee->employee_id);

                if ($user_data) {

                    $message = $from_user->name . " has added a brief for project " . $project_detail->task_title;

                    // Pass nature to insertNotification
                    insertNotificationWithNature($user_data->id, $from_user->id, $type, $message, $nature, $project_id);
                }
            }

        }

    }


    function projectAssignedNotification($project_id, $type){

    }


/*
|--------------------------------------------------------------------------
| Email notification helpers (professional templates, customer-safe)
|--------------------------------------------------------------------------
*/

if (!function_exists('frontendUrl')) {
    // Builds a link into the React web app (not the API).
    function frontendUrl($path = '')
    {
        $base = rtrim(env('FRONTEND_URL', 'http://archilance.org'), '/');
        return $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('sendNotificationEmail')) {
    /**
     * Send a professional notification email to a single user — UNLESS they are
     * a customer / customer team member (user_role 4 or 5) or have no email.
     * $data is handed to the mails.notification template. Never throws.
     */
    function sendNotificationEmail($toUser, $subject, array $data)
    {
        if (!$toUser || empty($toUser->email)) {
            return;
        }
        // Business rule: never email customers (role 4) or their team members (role 5).
        if (in_array((int) ($toUser->user_role ?? 0), [4, 5], true)) {
            return;
        }

        if (empty($data['greetingName'])) {
            $first = trim((string) ($toUser->name ?? ''));
            $data['greetingName'] = $first !== '' ? explode(' ', $first)[0] : 'there';
        }

        try {
            \Mail::send('mails.notification', $data, function ($message) use ($toUser, $subject) {
                $message->from('info@archilance.net', 'Archilance LLC')
                    ->to($toUser->email)
                    ->subject($subject);
            });
        } catch (\Throwable $e) {
            \Log::warning('Notification email failed for user ' . ($toUser->id ?? '?') . ': ' . $e->getMessage());
        }
    }
}

if (!function_exists('sendAssignmentEmail')) {
    // Email a user that they've been assigned to a job (project) or a task.
    function sendAssignmentEmail($toUser, $fromUser, $type, $refId)
    {
        if (!$toUser || !$fromUser) {
            return;
        }

        if ($type === 'project_assigned') {
            $p = Project::find($refId);
            if (!$p) {
                return;
            }
            $details = [['label' => 'Job', 'value' => $p->project_name]];
            if (!empty($p->due_date)) {
                $details[] = ['label' => 'Due date', 'value' => date('M j, Y', strtotime($p->due_date))];
            }
            if (!empty($p->status)) {
                $details[] = ['label' => 'Status', 'badge' => ['text' => $p->status, 'kind' => 'status']];
            }
            sendNotificationEmail($toUser, 'You’ve been assigned to a job: ' . $p->project_name . ' — Archilance', [
                'emoji'   => '📌',
                'heading' => 'New assignment',
                'intro'   => e($fromUser->name) . ' assigned you to the job <strong>' . e($p->project_name) . '</strong>.',
                'details' => $details,
                'cta'     => ['text' => 'View job', 'url' => frontendUrl('/jobs/' . $p->id)],
                'signoff' => 'Jump in whenever you’re ready.',
            ]);
        } elseif ($type === 'task_assigned') {
            $t = ProjectTask::find($refId);
            if (!$t) {
                return;
            }
            $details = [['label' => 'Task', 'value' => $t->task_title]];
            if (!empty($t->due_date)) {
                $details[] = ['label' => 'Due date', 'value' => date('M j, Y', strtotime($t->due_date))];
            }
            if (!empty($t->task_status)) {
                $details[] = ['label' => 'Status', 'badge' => ['text' => $t->task_status, 'kind' => 'status']];
            }
            if (!empty($t->priority)) {
                $details[] = ['label' => 'Priority', 'badge' => ['text' => $t->priority, 'kind' => 'priority']];
            }
            sendNotificationEmail($toUser, 'You’ve been assigned to: ' . $t->task_title . ' — Archilance', [
                'emoji'   => '📌',
                'heading' => 'New assignment',
                'intro'   => e($fromUser->name) . ' assigned you to <strong>' . e($t->task_title) . '</strong>.',
                'details' => $details,
                'cta'     => ['text' => 'View task', 'url' => frontendUrl('/project/' . $t->id)],
                'signoff' => 'Jump in whenever you’re ready.',
            ]);
        }
    }
}

if (!function_exists('commentAddedNotification')) {
    /**
     * In-app + email notification when a comment is added to a task. Recipients:
     * task assignees + all admins + tagged users, minus the commenter. Emails
     * skip customers automatically (via sendNotificationEmail).
     */
    function commentAddedNotification($taskId, $commentText, $taggedUserIds = [])
    {
        $from_user = User::find(Auth::user()->id);
        if (!$from_user) {
            return;
        }
        $task = ProjectTask::with('project')->find($taskId);
        if (!$task) {
            return;
        }

        $recipientIds = [];
        foreach (TaskAssignee::where('task_id', $taskId)->pluck('employee_id') as $eid) {
            $recipientIds[$eid] = true;
        }
        foreach (User::where('user_role', 2)->pluck('id') as $aid) {
            $recipientIds[$aid] = true;
        }
        foreach ((array) $taggedUserIds as $tid) {
            if ($tid) {
                $recipientIds[$tid] = true;
            }
        }
        unset($recipientIds[$from_user->id]); // never notify the commenter

        if (empty($recipientIds)) {
            return;
        }

        $preview = \Illuminate\Support\Str::limit(trim(strip_tags((string) $commentText)), 160);
        if ($preview === '') {
            $preview = 'Sent an attachment';
        }
        $projectName = optional($task->project)->project_name;

        foreach (array_keys($recipientIds) as $uid) {
            $user_data = User::find($uid);
            if (!$user_data) {
                continue;
            }
            $isTagged = in_array($uid, (array) $taggedUserIds);
            $message  = $from_user->name . ($isTagged ? ' mentioned you in a comment on ' : ' commented on ') . $task->task_title;

            insertNotificationWithNature($user_data->id, $from_user->id, 'task_comment_added', $message, 'primary', $taskId);

            $details = [];
            if ($projectName) {
                $details[] = ['label' => 'Job', 'value' => $projectName];
            }
            $details[] = ['label' => 'Task', 'value' => $task->task_title];
            if (!empty($task->task_status)) {
                $details[] = ['label' => 'Status', 'badge' => ['text' => $task->task_status, 'kind' => 'status']];
            }

            sendNotificationEmail($user_data, 'New comment on “' . $task->task_title . '” — Archilance', [
                'emoji'       => '🗨️',
                'heading'     => $isTagged ? 'You were mentioned' : 'New comment',
                'intro'       => e($from_user->name) . ($isTagged ? ' mentioned you in a comment on <strong>' : ' left a comment on <strong>') . e($task->task_title) . '</strong>.',
                'quoteAuthor' => $from_user->name,
                'quote'       => $preview,
                'details'     => $details,
                'cta'         => ['text' => 'View task', 'url' => frontendUrl('/project/' . $taskId)],
            ]);
        }
    }
}

if (!function_exists('chatMessageNotification')) {
    /**
     * In-app (always) + email (when $sendEmail) for a 1:1 chat message.
     * Email skips customers automatically.
     */
    function chatMessageNotification($toUser, $fromUser, $messageText, $sendEmail = true)
    {
        if (!$toUser || !$fromUser) {
            return;
        }

        insertNotificationWithNature(
            $toUser->id,
            $fromUser->id,
            'chat_message',
            $fromUser->name . ' sent you a message',
            'primary',
            $fromUser->id
        );

        if (!$sendEmail) {
            return;
        }

        $preview = \Illuminate\Support\Str::limit(trim(strip_tags((string) $messageText)), 160);
        if ($preview === '') {
            $preview = 'Sent you an attachment';
        }

        sendNotificationEmail($toUser, $fromUser->name . ' sent you a message — Archilance', [
            'emoji'       => '💬',
            'heading'     => 'New message',
            'intro'       => '<strong>' . e($fromUser->name) . '</strong> sent you a new message.',
            'quoteAuthor' => $fromUser->name,
            'quote'       => $preview,
            'cta'         => ['text' => 'Open chat', 'url' => frontendUrl('/chat')],
            'signoff'     => 'Reply directly inside Archilance.',
        ]);
    }
}


    function dueChangedNotification($project_id, $due_date, $type){

        $from_user = User::find(Auth::user()->id);

        $nature = "warning";
        
        if ($type == "project_due_date_updated") {

            $projAssignees = ProjectAssignee::where('project_id', $project_id)->get();
            $project_detail = Project::find($project_id);

            foreach ($projAssignees as $assignee) {

                $user_data = User::find($assignee->employee_id);

                if ($user_data) {

                    $message = $from_user->name . " has updated due date (".$due_date.") for project " . $project_detail->project_name;

                    // Pass nature to insertNotification
                    insertNotificationWithNature($user_data->id, $from_user->id, $type, $message, $nature, $project_id);
                }
            }

        }


        if ($type == "task_due_date_updated") {

            $projAssignees = TaskAssignee::where('task_id', $project_id)->get();
            $project_detail = ProjectTask::find($project_id);

            foreach ($projAssignees as $assignee) {

                $user_data = User::find($assignee->employee_id);

                if ($user_data) {

                    $message = $from_user->name . " has updated due date (".$due_date.") for project " . $project_detail->task_title;

                    // Pass nature to insertNotification
                    insertNotificationWithNature($user_data->id, $from_user->id, $type, $message, $nature, $project_id);
                }
            }

        }

    }


    function insertNotificationWithNature($user_id, $from_user_id, $type, $message, $nature, $project_id)
    {
        $new_notification = new Notification();
        $new_notification->user_id = $user_id;
        $new_notification->from_user_id = $from_user_id;
        $new_notification->notification_type = $type;
        $new_notification->notification_message = $message;
        $new_notification->notification_nature = $nature; // NEW FIELD
        $new_notification->project_id = $project_id;
        $new_notification->save();
    }