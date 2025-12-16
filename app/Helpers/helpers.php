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