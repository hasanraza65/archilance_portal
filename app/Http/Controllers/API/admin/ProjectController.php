<?php

namespace App\Http\Controllers\api\admin;

use App\Http\Controllers\Controller;
use App\Models\ProjectTask;
use Illuminate\Http\Request;
use App\Models\Project;
use App\Models\ProjectAssignee;
use App\Models\User;
use Firebase\JWT\JWT;
use App\Helpers\FirebaseHelper;
use App\Models\WorkSession;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ProjectController extends Controller
{
    public function index()
{
    $statusOrder = [
        'On Hold' => 1,
        'Backlog' => 2,
        'Awaiting Info' => 3,
        'In Progress' => 4,
        'In-house review' => 5,
        'Client Review' => 6,
        'Completed' => 7,
    ];

    // ✅ Fetch all projects with relations
    $projects = Project::latest()
        ->with(['customer', 'projectAssignees', 'projectAssignees.user'])
        ->get();

    // ✅ Group projects by status
    $grouped = [];
    foreach ($projects as $project) {
        $status = $project->status ?? 'Unknown';
        $grouped[$status][] = $project;
    }

    // ✅ Sort groups based on $statusOrder
    uksort($grouped, function ($a, $b) use ($statusOrder) {
        $orderA = $statusOrder[$a] ?? 999;
        $orderB = $statusOrder[$b] ?? 999;
        return $orderA <=> $orderB;
    });

    return response()->json($grouped);
}


    public function projectsWithTasks(Request $request)
    {
        $statusOrder = [
            'On Hold' => 1,
            'Backlog' => 2,
            'Awaiting Info' => 3,
            'In Progress' => 4,
            'In-house review' => 5,
            'Client Review' => 6,
            'Completed' => 7,
        ];

        $query = ProjectTask::with([
            'project',
            'assignees',
            'assignees.user',
            'creator',
            'attachments',
            'subTasks',
            'subTasks.assignees',
            'subTasks.assignees.user',
            'subTasks.creator',
            'subTasks.attachments'
        ])
         ->whereNull('parent_task_id') // Only main tasks
            ->where('task_status', '!=', 'Todo');

        if ($request->filled('task_status')) {
            $query->where('task_status', $request->task_status);
        }

        $mainTasks = $query->get();

        $result = [];
        foreach ($mainTasks as $task) {
            if ($task->subTasks->isNotEmpty()) {
                foreach ($task->subTasks as $sub) {
                    $result[] = [
                        'project' => $task->project,
                        'task' => $task,
                        'sub_task' => $sub,
                    ];
                }
            } else {
                $result[] = [
                    'project' => $task->project,
                    'task' => $task,
                    'sub_task' => null,
                ];
            }
        }

        // Sort the final result array by task status in the desired order
        usort($result, function ($a, $b) use ($statusOrder) {
            // Determine which status to use for sorting
            $statusA = $a['sub_task'] ? $a['sub_task']->task_status : $a['task']->task_status;
            $statusB = $b['sub_task'] ? $b['sub_task']->task_status : $b['task']->task_status;

            $orderA = $statusOrder[$statusA] ?? 999;
            $orderB = $statusOrder[$statusB] ?? 999;

            return $orderA <=> $orderB;
        });

        return response()->json($result);
    }



    public function projectsWithMember(Request $request)
    {
        $statusOrder = [
            'On Hold' => 1,
            'Backlog' => 2,
            'Awaiting Info' => 3,
            'In Progress' => 4,
            'In-house review' => 5,
            'Client Review' => 6,
            'Completed' => 7,
        ];

        $users = User::with([
            'assignedTasks' => function ($q) {
                $q->with([
                    'project',
                    'parentTask',
                ])->where('task_status', '!=', 'Todo');
            }
        ])
            ->withCount([
                'assignedTasks as total_tasks' => function ($q) {
                    $q->where('task_status', '!=', 'Todo');
                    $q->where('task_status', '!=', 'Completed');
                    $q->where('task_status', '!=', 'Client Review');
                    $q->where('task_status', '!=', 'Awaiting Info');
                    $q->where('task_status', '!=', 'On Hold');
                }
            ])
            ->where('user_role', 3)
            ->get();

        // Transform: group tasks by status & count them
        $users = $users->map(function ($user) use ($statusOrder) {
            $grouped = $user->assignedTasks
                ->groupBy('task_status')
                ->map(function ($tasks, $status) {
                    return [
                        'count' => $tasks->count(),
                        'tasks' => $tasks,
                    ];
                });

            // Ensure all statuses appear, even if count = 0
            $ordered = collect($statusOrder)->mapWithKeys(function ($order, $status) use ($grouped) {
                return [
                    $status => $grouped->get($status, [
                        'count' => 0,
                        'tasks' => collect(),
                    ])
                ];
            });

            $user->tasks_by_status = $ordered;

            return $user;
        });

        return response()->json($users);
    }




    public function store(Request $request)
    {
        $validated = $request->validate([
            'project_name' => 'required|string|max:255',
            'project_description' => 'nullable|string',
            'start_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'delivered_date' => 'nullable|date',
            'status' => 'nullable|string',
            'customer_id' => 'nullable|exists:users,id',
        ]);

        $project = Project::create($validated);

        //add project assignees 

        for ($i = 0; $i < count($request->employee_ids); $i++) {

            $proj_assignee = new ProjectAssignee();
            $proj_assignee->employee_id = $request->employee_ids[$i];
            $proj_assignee->project_id = $project->id;
            $proj_assignee->save();

        }

        //ending add project assignees

        return response()->json([
            'message' => 'Project created successfully.',
            'project' => $project,
        ]);
    }

    public function updateProjectAssignees(Request $request)
    {

        if (count($request->employee_ids) > 0) {

            ProjectAssignee::where('project_id', $request->project_id)->delete();

            for ($i = 0; $i < count($request->employee_ids); $i++) {

                $proj_assignee = new ProjectAssignee();
                $proj_assignee->employee_id = $request->employee_ids[$i];
                $proj_assignee->project_id = $request->project_id;
                $proj_assignee->save();

                $user = User::find($request->employee_ids[$i]);

                if ($user && $user->fcm_token) {

                    FirebaseHelper::sendFcmNotification($user->fcm_token, "New Task", "A task/project was assigned.");

                }

            }

        }

        $project = Project::find($request->project_id);

        return response()->json([
            'message' => 'Project created successfully.',
            'project' => $project,
        ]);

    }


    public function show(Request $request, $id)
    {
        $project = Project::with([
            'projectAssignees',
            'projectAssignees.user',
            'customer',
            'allTasks',
            'tasks',
            'tasks.creator',
            'tasks.assignees',
            'tasks.assignees.user',
            'tasks.attachments',
            'allBriefs',
            'allBriefs.attachments'
        ])->findOrFail($id);

        $taskHours = [];

        // 1. Calculate hours for every task (including child tasks)
        // Get date filters (if supplied)
        $startDateFilter = $request->summary_start_date ?? null;
        $endDateFilter = $request->summary_end_date ?? null;
        
      //  \Log::info($project->allTasks);

       foreach ($project->allTasks as $task) {
            $taskHours[$task->id] = $this->calculateEmployeeTaskHours(
                null,
                $task->id,
                $startDateFilter,
                $endDateFilter
            );
        }
        // 2. Roll up child tasks into parent
        $rolledUpHours = [];
        foreach ($project->allTasks as $task) {
            $hours = $taskHours[$task->id] ?? 0;

            if ($task->parent_task_id) {
                // add to parent’s hours
               // \Log::info('child task hours '.$hours.' for id '.$task->id);
                $rolledUpHours[$task->parent_task_id] = ($rolledUpHours[$task->parent_task_id] ?? 0) + $hours;
            } else {
                // parent task itself
                $rolledUpHours[$task->id] = ($rolledUpHours[$task->id] ?? 0) + $hours;
            }
        }

        // 3. Build array of parent tasks with their total hours
        $parentTasksWithHours = [];
        foreach ($project->tasks->whereNull('parent_task_id') as $parentTask) {
            
            $totalHours = $rolledUpHours[$parentTask->id] ?? 0;
            $parentTasksWithHours[] = [
                'task_id' => $parentTask->id,
                'task_title' => $parentTask->task_title,
                'total_hours' => $totalHours,
                'total_hours_formatted' => $this->formatHours($totalHours),
            ];
        }

        // 4. Attach to project response
        $project->tasks_hours_summary = $parentTasksWithHours;

        return response()->json($project);
    }
    

    private function calculateEmployeeTaskHours($employeeId=null, $taskId, $startDateFilter = null, $endDateFilter = null)
    {
        
        $sessionsQuery = WorkSession::where('task_id', $taskId);

    
        // Apply date range if provided
        if ($startDateFilter && $endDateFilter) {
            $sessionsQuery->where(function ($q) use ($startDateFilter, $endDateFilter) {
                $q->whereBetween('start_date', [$startDateFilter, $endDateFilter])
                  ->orWhereBetween('end_date', [$startDateFilter, $endDateFilter])
                  ->orWhere(function ($q2) use ($startDateFilter, $endDateFilter) {
                      $q2->where('start_date', '<', $startDateFilter)
                         ->where('end_date', '>', $endDateFilter);
                  });
            });
        } elseif ($startDateFilter) {
            $sessionsQuery->where(function ($q) use ($startDateFilter) {
                $q->whereDate('start_date', '>=', $startDateFilter)
                  ->orWhereDate('end_date', '>=', $startDateFilter);
            });
        } elseif ($endDateFilter) {
            $sessionsQuery->where(function ($q) use ($endDateFilter) {
                $q->whereDate('start_date', '<=', $endDateFilter)
                  ->orWhereDate('end_date', '<=', $endDateFilter);
            });
        }
    
        $sessions = $sessionsQuery->get();
        
       // \Log::info($sessions);
    
        $totalSeconds = 0;
        $dateWiseTotals = [];
        $dateWiseAdjustments = [];
    
        foreach ($sessions as $session) {
            try {
                // Parse start time
                $sessionStart = Carbon::parse($session->start_date . ' ' . $session->start_time);
    
                // Parse end time (handle running sessions and null end dates)
                if (is_null($session->end_time)) {
                    $sessionEnd = now();
                } else {
                    $endDate = $session->end_date ?? $session->start_date;
                    $sessionEnd = Carbon::parse($endDate . ' ' . $session->end_time);
                }
                
                \Log::info('session start date '.$session->start_date);
                \Log::info('session end date '.$session->end_date);
                
                \Log::info('session start time '.$session->start_time);
                \Log::info('session end time '.$session->end_time);
    
                // Calculate session duration
                $sessionDuration = abs($sessionEnd->diffInSeconds($sessionStart));
    
                // Subtract adjustments
                $adjustmentSeconds = 0;
                $adjustments = DB::table('session_time_adjustments')
                    ->where('session_id', $session->id)
                    ->get();
    
                foreach ($adjustments as $adj) {
                    if (empty($adj->start_time) || empty($adj->end_time)) {
                        continue;
                    }
    
                    try {
                        $adjStart = Carbon::parse($adj->start_time);
                        $adjEnd = Carbon::parse($adj->end_time);
                        $adjustmentDuration = abs($adjEnd->diffInSeconds($adjStart));
                        $adjustmentSeconds += $adjustmentDuration;
                    } catch (\Exception $e) {
                        continue;
                    }
                }
    
                // Compute net worked time
                $netSeconds = $sessionDuration - $adjustmentSeconds;
               $netSeconds = $sessionDuration;
    
                if ($netSeconds > 0) {
                    $totalSeconds += $netSeconds;
    
                    $date = Carbon::parse($session->start_date)->format('Y-m-d');
    
                    if (!isset($dateWiseTotals[$date])) {
                        $dateWiseTotals[$date] = 0;
                    }
                    if (!isset($dateWiseAdjustments[$date])) {
                        $dateWiseAdjustments[$date] = 0;
                    }
    
                    $dateWiseTotals[$date] += $netSeconds;
                    $dateWiseAdjustments[$date] += $adjustmentSeconds;
                }
    
            } catch (\Exception $e) {
                continue;
            }
        }
    
        // ✅ Log date-wise totals and adjustments
        foreach ($dateWiseTotals as $date => $seconds) {
            $hours = round($seconds / 3600, 2);
            $adjustHrs = isset($dateWiseAdjustments[$date]) ? round($dateWiseAdjustments[$date] / 3600, 2) : 0;
            $totalWithAdj = round(($seconds + $dateWiseAdjustments[$date]) / 3600, 2);
    
           // \Log::info("[$date] Employee $employeeId - Task $taskId: Worked {$hours} hrs | Adjusted {$adjustHrs} hrs | Original {$totalWithAdj} hrs");
        }
    
        return $totalSeconds;
    }


    

    // Helper method to format seconds into hours and minutes
    private function formatHours($seconds)
    {
        if ($seconds <= 0) {
            return '0h 0m';
        }

        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        return sprintf('%dh %dm', $hours, $minutes);
    }



    public function update(Request $request, $id)
    {
        $project = Project::findOrFail($id);

        $validated = $request->validate([
            'project_name' => 'sometimes|string|max:255',
            'project_description' => 'nullable|string',
            'start_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'delivered_date' => 'nullable|date',
            'status' => 'in:In Progress,Pending,Completed,Cancelled',
            'customer_id' => 'nullable|exists:users,id',
        ]);

        $project->update($validated);

        return response()->json([
            'message' => 'Project updated successfully.',
            'project' => $project,
        ]);
    }

    public function updateStatus(Request $request)
    {

        $project = Project::findOrFail($request->project_id);
        $project->status = $request->status;
        $project->save();

        if ($project->status == "Completed") {

            ProjectTask::where('project_id', $request->project_id)->update(['task_status' => "Completed"]);

        }

        return response()->json([
            'message' => 'Project updated successfully.',
            'project' => $project,
        ]);

    }

    public function destroy($id)
    {
        $project = Project::findOrFail($id);
        $project->delete();

        return response()->json([
            'message' => 'Project deleted successfully.',
        ]);
    }
    
    
    public function testData(){
        
        $data = ProjectTask::where('project_id',26)->pluck('id')->toArray();
        
        $works = WorkSession::whereIn('task_id', $data)
            ->selectRaw('user_id, COUNT(*) as total_sessions')
            ->groupBy('user_id')
            ->get();

        
        return response()->json($works);
            
        
    }


}
