<?php

namespace App\Http\Controllers\API\customer;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectTask;
use Illuminate\Http\Request;
use App\Models\Screenshot;
use App\Models\WorkSession;
use Auth;
use Carbon\Carbon;
use App\Models\CustomerTeam;
use App\Models\TrackWindow;


class WorkSessionController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();

        // Use current date if not provided
        $startDate = $request->start_date ?? now()->toDateString();
        $endDate = $request->end_date ?? now()->toDateString();

        // Ensure project_id is provided
        if (!$request->has('project_id')) {
            return response()->json(['error' => 'Project ID is required'], 422);
        }

        $task_ids = ProjectTask::where('project_id', $request->project_id)->pluck('id')->toArray();

        $query = WorkSession::with('screenshots', 'taskDetail', 'userDetail')
            ->whereNotNull('end_time')
            ->whereDate('created_at', '>=', $startDate)
            ->whereDate('created_at', '<=', $endDate)
            ->whereIn('task_id', $task_ids);

        if ($user->user_role == 5) {
            // Team member: get associated customer IDs
            $customerIds = CustomerTeam::where('email', $user->email)
                ->where('status', 'Approved')
                ->pluck('customer_id');

            // Get project IDs linked to these customers (if needed you can cache this logic)
            $allowedProjectIds = Project::whereIn('customer_id', $customerIds)->pluck('id');

            // Make sure the project_id being queried belongs to the team member
            if (!$allowedProjectIds->contains($request->project_id)) {
                return response()->json(['error' => 'Unauthorized access to this project.'], 403);
            }

            // Only show work sessions for the authenticated team member
            $query->where('user_id', $user->id);
        } else {
            // Customer: show all their work sessions
            $query->where('user_id', $user->id);
        }

        $data = $query->orderBy('created_at', 'desc')->paginate(1000);

        return response()->json($data);
    }

}
