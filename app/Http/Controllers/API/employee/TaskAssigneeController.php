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
        // `nullable` on both rules, matching the ADMIN controller for this same
        // route. Clients clear ALL assignees by sending a single empty
        // employee_ids[] entry (v1 convention); Laravel's global middleware
        // turns "" into null, and without `nullable` that null failed `exists`
        // with "The selected employee_ids.0 is invalid." — so a role-3 user
        // could never unassign the last person from a task.
        $request->validate([
            'task_id'        => 'required|exists:project_tasks,id',
            'employee_ids'   => 'nullable|array',
            'employee_ids.*' => 'nullable|exists:users,id',
        ]);

        $taskId      = $request->task_id;
        $employeeIds = array_filter($request->employee_ids ?? []); // drop null/empty sentinels

        if (empty($employeeIds)) {
            // Empty list = remove everyone, same as the admin controller.
            TaskAssignee::where('task_id', $taskId)->delete();

            return response()->json([
                'message'  => 'All assignees removed.',
                'assigned' => [],
                'skipped'  => [],
            ]);
        }

        // Remove assignees no longer in the list
        TaskAssignee::where('task_id', $taskId)
            ->whereNotIn('employee_id', $employeeIds)
            ->delete();

        // ONE query: find which employees are already assigned
        $existingIds = TaskAssignee::where('task_id', $taskId)
            ->whereIn('employee_id', $employeeIds)
            ->pluck('employee_id')
            ->toArray();

        $newEmployeeIds = array_values(array_diff($employeeIds, $existingIds));
        $skipped        = $existingIds;
        $assigned       = [];

        if (!empty($newEmployeeIds)) {
            // ONE query: load all new employees for notifications
            $usersToNotify = User::whereIn('id', $newEmployeeIds)->get()->keyBy('id');
            $fromUser      = \Auth::user();
            $projectDetail = ProjectTask::find($taskId);

            foreach ($newEmployeeIds as $employeeId) {
                $assignee   = TaskAssignee::create(['task_id' => $taskId, 'employee_id' => $employeeId]);
                $assigned[] = $assignee;

                $user = $usersToNotify->get($employeeId);
                if (!$user) continue;

                if ($user->fcm_token) {
                    FirebaseHelper::sendFcmNotification($user->fcm_token, "New Task", "A task/project was assigned.");
                }

                $message = $fromUser->name . " has assigned you a project " . ($projectDetail->task_title ?? '');
                insertNotificationWithNature($user->id, $fromUser->id, "task_assigned", $message, "primary", $taskId);
                sendAssignmentEmail($user, $fromUser, "task_assigned", $taskId);
            }
        }

        return response()->json([
            'message'  => 'Bulk assignment completed.',
            'assigned' => $assigned,
            'skipped'  => $skipped,
        ]);
    }

}
