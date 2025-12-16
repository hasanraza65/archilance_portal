<?php

namespace App\Http\Controllers\api\admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\TaskAssignee;
use App\Models\ProjectTask;
use App\Models\User;
use App\Helpers\FirebaseHelper;

class TaskAssigneeController extends Controller
{
    // ✅ Get all assignees (optionally filtered by task_id)
    public function index(Request $request)
    {
        $query = TaskAssignee::with(['task', 'user']);

        if ($request->has('task_id')) {
            $query->where('task_id', $request->task_id);
        }

        $assignees = $query->get();
        return response()->json($assignees);
    }

    // ✅ Assign user to task
    public function store(Request $request)
    {
        $request->validate([
            'task_id' => 'required|exists:project_tasks,id',
            'employee_id' => 'required|exists:users,id',
        ]);

        $exists = TaskAssignee::where('task_id', $request->task_id)
            ->where('employee_id', $request->employee_id)
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'User already assigned to this task.'], 409);
        }

        $assignee = TaskAssignee::create([
            'task_id' => $request->task_id,
            'employee_id' => $request->employee_id,
        ]);

        // Get employee's FCM token
        $user = User::find($request->employee_id);

        if ($user && $user->fcm_token) {
            $this->sendFcmNotification(
                $user->fcm_token,
                "New Task Assigned",
                "A new project/task has been assigned to you."
            );
        }

        return response()->json([
            'message' => 'User assigned successfully.',
            'assignee' => $assignee
        ]);
    }



    // ✅ Show single assignment
    public function show($id)
    {
        $assignee = TaskAssignee::with(['task', 'user'])->findOrFail($id);
        return response()->json($assignee);
    }

    // ❌ Usually we don't update task assignments, but just in case:
    public function update(Request $request, $id)
    {
        $assignee = TaskAssignee::findOrFail($id);

        $request->validate([
            'task_id' => 'required|exists:project_tasks,id',
            'employee_id' => 'required|exists:users,id',
        ]);

        $assignee->update([
            'task_id' => $request->task_id,
            'employee_id' => $request->employee_id,
        ]);

        return response()->json(['message' => 'Assignment updated.', 'assignee' => $assignee]);
    }

    // ✅ Remove assignment
    public function destroy($id)
    {
        $assignee = TaskAssignee::findOrFail($id);
        $assignee->delete();

        return response()->json(['message' => 'Assignment removed.']);
    }

   public function bulkAssign(Request $request)
{
    $request->validate([
        'task_id' => 'required|exists:project_tasks,id',
        'employee_ids' => 'nullable|array',
        'employee_ids.*' => 'nullable|exists:users,id'
    ]);

    $taskId = $request->task_id;
    $employeeIds = array_filter($request->employee_ids ?? []); // remove null/empty

    if (empty($employeeIds)) {
        // If no IDs given, remove all assignees
        TaskAssignee::where('task_id', $taskId)->delete();

        return response()->json([
            'message' => 'All assignees removed.',
            'assigned' => [],
            'skipped' => []
        ]);
    }

    // Remove all assignees not in the current list
    TaskAssignee::where('task_id', $taskId)
        ->whereNotIn('employee_id', $employeeIds)
        ->delete();

    $assigned = [];
    $skipped = [];

    foreach ($employeeIds as $employeeId) {
        if (!$employeeId) {
            continue; // Skip null/empty
        }

        $exists = TaskAssignee::where('task_id', $taskId)
            ->where('employee_id', $employeeId)
            ->exists();

        if ($exists) {
            $skipped[] = $employeeId;
            continue;
        }

        $assignee = TaskAssignee::create([
            'task_id' => $taskId,
            'employee_id' => $employeeId
        ]);

        $assigned[] = $assignee;

        // Send FCM notification
        $user = User::find($employeeId);
        if ($user && $user->fcm_token) {
            
            FirebaseHelper::sendFcmNotification($user->fcm_token, "New Task", "A task/project was assigned.");
            
        }


      //  $user = User::find($request->employee_ids[$i]);
             $projectId = $taskId;

            if($user){

                $project_detail = ProjectTask::find($projectId);
                $from_user = User::find(\Auth::user()->id);
                $nature = "primary";
                $message = $from_user->name . " has assigned you a project " . $project_detail->task_title;

                insertNotificationWithNature($user->id, \Auth::user()->id, "task_assigned", $message, $nature, $projectId);
            }
    }

    return response()->json([
        'message' => 'Bulk assignment completed.',
        'assigned' => $assigned,
        'skipped' => $skipped
    ]);
}



    protected function sendFcmNotification($token, $title, $body)
    {
        $SERVER_API_KEY = env('FCM_SERVER_KEY'); // store in .env

        $data = [
            "registration_ids" => [$token], // can be multiple tokens
            "notification" => [
                "title" => $title,
                "body" => $body,
                "sound" => "default"
            ]
        ];

        $dataString = json_encode($data);

        $headers = [
            'Authorization: key=' . $SERVER_API_KEY,
            'Content-Type: application/json',
        ];

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, 'https://fcm.googleapis.com/fcm/send');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $dataString);

        $response = curl_exec($ch);

        curl_close($ch);

        return $response;
    }


}
