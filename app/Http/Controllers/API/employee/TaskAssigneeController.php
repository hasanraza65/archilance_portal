<?php

namespace App\Http\Controllers\API\employee;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\TaskAssignee;
use App\Models\ProjectTask;
use App\Models\User;
use Firebase\JWT\JWT;
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
            'task_id'     => 'required|exists:project_tasks,id',
            'employee_id' => 'required|exists:users,id',
        ]);

        $exists = TaskAssignee::where('task_id', $request->task_id)
                              ->where('employee_id', $request->employee_id)
                              ->exists();

        if ($exists) {
            return response()->json(['message' => 'User already assigned to this task.'], 409);
        }

        $assignee = TaskAssignee::create([
            'task_id'     => $request->task_id,
            'employee_id' => $request->employee_id,
        ]);

        return response()->json(['message' => 'User assigned successfully.', 'assignee' => $assignee]);
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
            'task_id'     => 'required|exists:project_tasks,id',
            'employee_id' => 'required|exists:users,id',
        ]);

        $assignee->update([
            'task_id'     => $request->task_id,
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
            'employee_ids' => 'required|array',
            'employee_ids.*' => 'exists:users,id'
        ]);

        $taskId = $request->task_id;
        $employeeIds = $request->employee_ids;

        // Step 1: Remove all assignees not in the current list
        TaskAssignee::where('task_id', $taskId)
            ->whereNotIn('employee_id', $employeeIds)
            ->delete();

        // Step 2: Assign new employees if not already assigned
        $assigned = [];
        $skipped = [];

        foreach ($employeeIds as $employeeId) {
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
        }

        return response()->json([
            'message' => 'Bulk assignment completed.',
            'assigned' => $assigned,
            'skipped' => $skipped
        ]);
    }

}
