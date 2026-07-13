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
    public function index(Request $request)
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

            $projects = Project::latest()
                ->with([
                    'customer:id,name,profile_pic',
                    'projectAssignees:id,employee_id,project_id',
                    'projectAssignees.user:id,name,profile_pic',
                ])
                ->when($request->customer_id, function ($query) use ($request) {
                    $query->where('customer_id', $request->customer_id);
                })
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
        $page    = max(1, (int) $request->input('page', 1));
        $perPage = 10;

        // Ordering that preserves the previous status-priority sort, then id
        $statusPriority = "FIELD(task_status, 'On Hold','Backlog','Awaiting Info','In Progress','In-house review','Client Review','Completed')";

        // Base parent-task query (filters only, no heavy relations yet)
        $baseParentQuery = ProjectTask::query()
            ->whereNull('parent_task_id')
            ->where('task_status', '!=', 'Todo');

        if ($request->filled('task_status')) {
            $baseParentQuery->where('task_status', $request->task_status);
        }

        // Phase 1: cheap skeleton — parent ids in the exact display order
        $parentIds = (clone $baseParentQuery)
            ->orderByRaw($statusPriority)
            ->orderBy('id')
            ->pluck('id');

        $subSkeleton = $parentIds->isEmpty()
            ? collect()
            : ProjectTask::whereIn('parent_task_id', $parentIds)
                ->orderBy('id')
                ->get(['id', 'parent_task_id'])
                ->groupBy('parent_task_id');

        // Build flat-row skeleton (identical expansion order to the previous implementation)
        $flat = [];
        foreach ($parentIds as $pid) {
            $subs = $subSkeleton->get($pid);
            if ($subs && $subs->isNotEmpty()) {
                foreach ($subs as $s) {
                    $flat[] = ['task_id' => $pid, 'sub_id' => $s->id];
                }
            } else {
                $flat[] = ['task_id' => $pid, 'sub_id' => null];
            }
        }

        $total    = count($flat);
        $offset   = ($page - 1) * $perPage;
        $pageRows = array_slice($flat, $offset, $perPage);

        // Phase 2: heavy-load ONLY the parent tasks needed for this page
        $neededParentIds = array_values(array_unique(array_map(fn($r) => $r['task_id'], $pageRows)));

        $parents = collect();
        if (!empty($neededParentIds)) {
            $parents = ProjectTask::with([
                'project',
                'assignees:id,employee_id,task_id',
                'assignees.user:id,name,profile_pic',
                'creator:id,name,profile_pic',
                'attachments',
                'subTasks',
                'subTasks.assignees:id,employee_id,task_id',
                'subTasks.assignees.user:id,name,profile_pic',
                'subTasks.creator:id,name,profile_pic',
                'subTasks.attachments',
            ])->whereIn('id', $neededParentIds)->get()->keyBy('id');
        }

        // Rebuild result rows in exact page order — same shape as before
        $paginated = [];
        foreach ($pageRows as $row) {
            $task = $parents->get($row['task_id']);
            if (!$task) {
                continue;
            }
            $sub = $row['sub_id'] !== null
                ? $task->subTasks->firstWhere('id', $row['sub_id'])
                : null;

            $paginated[] = [
                'project'  => $task->project,
                'task'     => $task,
                'sub_task' => $sub,
            ];
        }

        return response()->json([
            'data'         => $paginated,
            'current_page' => (int) $page,
            'per_page'     => $perPage,
            'total'        => $total,
            'last_page'    => (int) ceil($total / $perPage),
            'has_more'     => ($offset + $perPage) < $total,
        ]);
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


             $user = User::find($request->employee_ids[$i]);
             $projectId = $project->id;

            if($user){

                $project_detail = Project::find($projectId);
                $from_user = User::find(\Auth::user()->id);
                $nature = "primary";
                $message = $from_user->name . " has assigned you a job " . $project_detail->project_name;

                insertNotificationWithNature($user->id, \Auth::user()->id, "project_assigned", $message, $nature, $projectId);
            }

        }

        //ending add project assignees

        return response()->json([
            'message' => 'Project created successfully.',
            'project' => $project,
        ]);
    }

    public function updateProjectAssignees(Request $request)
    {
        $projectId = $request->project_id;
        $newEmployeeIds = $request->employee_ids ?? [];

        // STEP 1: Get old assignees before deleting
        $oldEmployeeIds = ProjectAssignee::where('project_id', $projectId)
                            ->pluck('employee_id')
                            ->toArray();

        // STEP 2: Find ONLY the newly added employees
        $newlyAdded = array_diff($newEmployeeIds, $oldEmployeeIds);

        // STEP 3: Delete old records
        ProjectAssignee::where('project_id', $projectId)->delete();

        // STEP 4: Insert new assignees
        foreach ($newEmployeeIds as $empId) {
            ProjectAssignee::create([
                'employee_id' => $empId,
                'project_id' => $projectId,
            ]);
        }

        // STEP 5: Send FCM notification ONLY to newly added employees
        foreach ($newlyAdded as $empId) {
            $user = User::find($empId);

            if ($user && $user->fcm_token) {
                FirebaseHelper::sendFcmNotification(
                    $user->fcm_token,
                    "New Task",
                    "A new task/project has been assigned to you."
                );
            }

            if($user){

                $project_detail = Project::find($projectId);
                $from_user = User::find(\Auth::user()->id);
                $nature = "primary";
                $message = $from_user->name . " has assigned you a job " . $project_detail->project_name;

                insertNotificationWithNature($user->id, \Auth::user()->id, "project_assigned", $message, $nature, $projectId);

            }
        }

        $project = Project::find($projectId);



        return response()->json([
            'message' => 'Project updated successfully.',
            'project' => $project,
        ]);
    }



    public function show(Request $request, $id)
    {
        // Lightweight mode (used by task-detail breadcrumb) — skip heavy task + hours computation
        if ($request->boolean('light')) {
            $project = Project::with(['customer:id,name,profile_pic'])->findOrFail($id);
            return response()->json($project);
        }

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
            'allBriefs.attachments',
            'allNotes'
        ])->findOrFail($id);

        $startDateFilter = $request->summary_start_date ?? null;
        $endDateFilter   = $request->summary_end_date   ?? null;

        $allTaskIds = $project->allTasks->pluck('id')->toArray();
        $taskHours  = [];

        if (!empty($allTaskIds)) {
            // 1. ONE query: all sessions for all tasks in this project
            $sessionsQuery = WorkSession::whereIn('task_id', $allTaskIds);

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

            $allSessions = $sessionsQuery->get();

            // 2. ONE query: all adjustments for those sessions
            $allSessionIds        = $allSessions->pluck('id')->toArray();
            $adjustmentsBySession = !empty($allSessionIds)
                ? DB::table('session_time_adjustments')
                    ->whereIn('session_id', $allSessionIds)
                    ->get()
                    ->groupBy('session_id')
                : collect();

            // 3. Group sessions by task_id; compute hours in PHP — zero extra queries
            $sessionsByTask = $allSessions->groupBy('task_id');

            foreach ($allTaskIds as $taskId) {
                $sessions     = $sessionsByTask->get($taskId, collect());
                $totalSeconds = 0;

                foreach ($sessions as $session) {
                    try {
                        $sessionStart = Carbon::parse($session->start_date . ' ' . $session->start_time);
                        $sessionEnd   = is_null($session->end_time)
                            ? now()
                            : Carbon::parse(($session->end_date ?? $session->start_date) . ' ' . $session->end_time);

                        $sessionDuration   = abs($sessionEnd->diffInSeconds($sessionStart));
                        $adjustmentSeconds = 0;

                        foreach ($adjustmentsBySession->get($session->id, collect()) as $adj) {
                            if (empty($adj->start_time) || empty($adj->end_time)) continue;
                            try {
                                $adjustmentSeconds += abs(
                                    Carbon::parse($adj->end_time)->diffInSeconds(Carbon::parse($adj->start_time))
                                );
                            } catch (\Exception $e) {
                                continue;
                            }
                        }

                        $netSeconds = $sessionDuration - $adjustmentSeconds;
                        if ($netSeconds > 0) {
                            $totalSeconds += $netSeconds;
                        }
                    } catch (\Exception $e) {
                        continue;
                    }
                }

                $taskHours[$taskId] = $totalSeconds;
            }
        } else {
            foreach ($project->allTasks as $task) {
                $taskHours[$task->id] = 0;
            }
        }

        // Roll up child task hours into their parent
        $rolledUpHours = [];
        foreach ($project->allTasks as $task) {
            $hours = $taskHours[$task->id] ?? 0;
            if ($task->parent_task_id) {
                $rolledUpHours[$task->parent_task_id] = ($rolledUpHours[$task->parent_task_id] ?? 0) + $hours;
            } else {
                $rolledUpHours[$task->id] = ($rolledUpHours[$task->id] ?? 0) + $hours;
            }
        }

        $parentTasksWithHours = [];
        foreach ($project->tasks->whereNull('parent_task_id') as $parentTask) {
            $totalHours             = $rolledUpHours[$parentTask->id] ?? 0;
            $parentTasksWithHours[] = [
                'task_id'               => $parentTask->id,
                'task_title'            => $parentTask->task_title,
                'total_hours'           => $totalHours,
                'total_hours_formatted' => $this->formatHours($totalHours),
            ];
        }

        $project->tasks_hours_summary = $parentTasksWithHours;

        return response()->json($project);
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

        if($project->due_date != $request->due_date){

              dueChangedNotification($id, $request->due_date, $type="project_due_date_updated");

        }

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

        statusChangedNotification($request->project_id, $request->status, $type="project_status_changed");

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
    
    
    public function projectsWithTasksCalendar(Request $request)
    {
        // --- Date resolution ---
        $targetDate = $request->input('date')
            ? Carbon::parse($request->input('date'))->startOfDay()
            : Carbon::today();

        $page    = max(1, (int) $request->input('page', 1));
        $perPage = 10;

        // --- Base parent-task query (filters only, no heavy relations yet) ---
        $baseParentQuery = ProjectTask::query()
            ->whereNull('parent_task_id')
            ->whereDate('due_date', $targetDate)
            ->whereNull('completed_date');

        // Phase 1: cheap skeleton — parent ids + project ids, and subtask ids
        $parentSkeleton = (clone $baseParentQuery)->orderBy('id')->get(['id', 'project_id']);
        $parentIds = $parentSkeleton->pluck('id');

        $subSkeleton = $parentIds->isEmpty()
            ? collect()
            : ProjectTask::whereIn('parent_task_id', $parentIds)
                ->orderBy('id')
                ->get(['id', 'parent_task_id'])
                ->groupBy('parent_task_id');

        // Build flat-row skeleton (same expansion order as before)
        $flat = [];
        foreach ($parentIds as $pid) {
            $subs = $subSkeleton->get($pid);
            if ($subs && $subs->isNotEmpty()) {
                foreach ($subs as $s) {
                    $flat[] = ['task_id' => $pid, 'sub_id' => $s->id];
                }
            } else {
                $flat[] = ['task_id' => $pid, 'sub_id' => null];
            }
        }

        $total     = count($flat);
        $offset    = ($page - 1) * $perPage;
        $pageRows  = array_slice($flat, $offset, $perPage);

        // --- Summary counts for the date (from the cheap skeleton, before pagination) ---
        $totalSubIds = 0;
        foreach ($subSkeleton as $group) {
            $totalSubIds += $group->count();
        }
        $uniqueTaskIds    = $parentIds->count();
        $uniqueSubIds     = $totalSubIds;
        $uniqueProjectIds = $parentSkeleton->pluck('project_id')->unique()->count();

        // Phase 2: heavy-load ONLY the parent tasks needed for this page
        $neededParentIds = array_values(array_unique(array_map(fn($r) => $r['task_id'], $pageRows)));

        $parents = collect();
        if (!empty($neededParentIds)) {
            $parents = ProjectTask::with([
                'project',
                'assignees:id,employee_id,task_id',
                'assignees.user:id,name,profile_pic',
                'creator:id,name,profile_pic',
                'attachments',
                'subTasks',
                'subTasks.assignees:id,employee_id,task_id',
                'subTasks.assignees.user:id,name,profile_pic',
                'subTasks.creator:id,name,profile_pic',
                'subTasks.attachments',
            ])->whereIn('id', $neededParentIds)->get()->keyBy('id');
        }

        // Rebuild rows in exact page order — same shape as before
        $paginated = [];
        foreach ($pageRows as $row) {
            $task = $parents->get($row['task_id']);
            if (!$task) {
                continue;
            }
            $sub = $row['sub_id'] !== null
                ? $task->subTasks->firstWhere('id', $row['sub_id'])
                : null;

            $paginated[] = [
                'project'  => $task->project,
                'task'     => $task,
                'sub_task' => $sub,
            ];
        }

        return response()->json([
            'date'         => $targetDate->toDateString(),
            'summary'      => [
                'total_projects'  => $uniqueProjectIds,
                'total_tasks'     => $uniqueTaskIds,
                'total_sub_tasks' => $uniqueSubIds,
                'total_rows'      => $total,
            ],
            'data'         => $paginated,
            'current_page' => (int) $page,
            'per_page'     => $perPage,
            'total'        => $total,
            'last_page'    => (int) ceil($total / $perPage),
            'has_more'     => ($offset + $perPage) < $total,
        ]);
    }


}
