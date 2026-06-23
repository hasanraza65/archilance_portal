<?php

namespace App\Http\Controllers\API\employee;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectAssignee;
use Auth;
use App\Models\WorkSession;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Models\User;

class ProjectController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();

        // Electron time tracker — slim paginated response, no heavy relations
        $userAgent = $request->header('User-Agent', '');
        if (strpos($userAgent, 'Electron') !== false) {
            return $this->indexForElectron($request, $user);
        }

        $statusOrder = [
            'On Hold' => 1,
            'Backlog' => 2,
            'Awaiting Info' => 3,
            'In Progress' => 4,
            'In-house review' => 5,
            'Client Review' => 6,
            'Completed' => 7,
        ];

        $withRelations = [
            'customer:id,name,profile_pic',
            'projectAssignees:id,employee_id,project_id',
            'projectAssignees.user:id,name,profile_pic',
        ];

        if (
            ($user->employee_type == "Manager" || $user->employee_type == "Supervisor" || $user->employee_type == "Executive")
            && !$request->boolean('assigned_me')
        ) {
            $projects = Project::latest()
                ->with($withRelations)
                ->when($request->customer_id, function ($query) use ($request) {
                    $query->where('customer_id', $request->customer_id);
                })
                ->get();
        } else {
            $userId = $user->id;

            // Single join query instead of 3 sequential queries
            $linkedProjectIds = ProjectAssignee::where('employee_id', $userId)
                ->pluck('project_id');

            $taskProjectIds = DB::table('task_assignees')
                ->join('project_tasks', 'task_assignees.task_id', '=', 'project_tasks.id')
                ->where('task_assignees.employee_id', $userId)
                ->whereNull('project_tasks.deleted_at')
                ->pluck('project_tasks.project_id');

            $all_project_ids = $linkedProjectIds->merge($taskProjectIds)->unique()->values()->toArray();

            $projects = Project::latest()
                ->with($withRelations)
                ->whereIn('id', $all_project_ids)
                ->when($request->customer_id, function ($query) use ($request) {
                    $query->where('customer_id', $request->customer_id);
                })
                ->get();
        }

        // ✅ Group by status
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

        $userAgent = $request->header('User-Agent');
        if (strpos($userAgent, 'Electron') !== false) {
            return response()->json($projects); // old structure
        }

        return response()->json($grouped);
    }


    private function indexForElectron(Request $request, $user)
    {
        $page    = max(1, (int) $request->input('page', 1));
        $perPage = 20;
        $search  = trim($request->input('search', ''));

        $isPrivileged = (
            $user->employee_type == 'Manager' ||
            $user->employee_type == 'Supervisor' ||
            $user->employee_type == 'Executive'
        ) && !$request->boolean('assigned_me');

        $query = Project::select(['id', 'project_name', 'status', 'project_description', 'created_at'])
            ->latest();

        if (!$isPrivileged) {
            $userId = $user->id;

            $linkedIds = ProjectAssignee::where('employee_id', $userId)->pluck('project_id');

            $taskIds = DB::table('task_assignees')
                ->join('project_tasks', 'task_assignees.task_id', '=', 'project_tasks.id')
                ->where('task_assignees.employee_id', $userId)
                ->whereNull('project_tasks.deleted_at')
                ->pluck('project_tasks.project_id');

            $allIds = $linkedIds->merge($taskIds)->unique()->values();
            $query->whereIn('id', $allIds);
        }

        if ($search !== '') {
            $query->where('project_name', 'like', '%' . $search . '%');
        }

        $paginated = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data'         => $paginated->items(),
            'total'        => $paginated->total(),
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
            'has_more'     => $paginated->hasMorePages(),
        ]);
    }

    public function projectsWithTasks(Request $request)
    {
        $user = Auth::user();
        $task_status = $request->task_status ?? 'On Hold';
        $page = $request->input('page', 1);
        $perPage = 10;

        $isPrivileged = ($user->employee_type == "Manager" || $user->employee_type == "Supervisor" || $user->employee_type == "Executive")
            && !$request->boolean('assigned_me');

        $query = ProjectTask::with([
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
        ])
            ->whereNull('parent_task_id')
            ->where('task_status', $task_status);

        if (!$isPrivileged) {
            $userId = $user->id;

            // Subqueries instead of two separate pluck() calls
            $query->where(function ($q) use ($userId) {
                $q->whereIn('project_id', function ($sq) use ($userId) {
                    $sq->select('project_id')
                        ->from('project_assignees')
                        ->where('employee_id', $userId);
                })->orWhereIn('id', function ($sq) use ($userId) {
                    $sq->select('task_id')
                        ->from('task_assignees')
                        ->where('employee_id', $userId);
                });
            });
        }

        // ✅ Get ALL tasks (no paginate here, because subtasks expand row count)
        $mainTasks = $query->get();

        // --- Build flat result first ---
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

        // ✅ Paginate the flat result array AFTER expansion
        $total = count($result);
        $offset = ($page - 1) * $perPage;
        $paginated = array_slice($result, $offset, $perPage);

        // ✅ Return same data shape + pagination meta
        return response()->json([
            'data' => $paginated,
            'current_page' => (int) $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => (int) ceil($total / $perPage),
            'has_more' => ($offset + $perPage) < $total,
        ]);
    }

    public function projectsWithTasksCalendar(Request $request)
    {
        $user = Auth::user();

        // --- Date resolution ---
        $targetDate = $request->input('date')
            ? Carbon::parse($request->input('date'))->startOfDay()
            : Carbon::today();

        $page = $request->input('page', 1);
        $perPage = 10;

        // --- Privilege check (same logic as projectsWithTasks) ---
        $isPrivileged = ($user->employee_type == "Manager"
            || $user->employee_type == "Supervisor"
            || $user->employee_type == "Executive")
            && !$request->boolean('assigned_me');

        $query = ProjectTask::with([
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
        ])
            ->whereNull('parent_task_id')
            ->where(function ($q) use ($targetDate) {
                $q->whereDate('start_date', '<=', $targetDate)
                    ->whereDate('due_date', '>=', $targetDate);
            });

        if (!$isPrivileged) {
            $userId = $user->id;

            $query->where(function ($q) use ($userId) {
                $q->whereIn('project_id', function ($sq) use ($userId) {
                    $sq->select('project_id')
                        ->from('project_assignees')
                        ->where('employee_id', $userId);
                })->orWhereIn('id', function ($sq) use ($userId) {
                    $sq->select('task_id')
                        ->from('task_assignees')
                        ->where('employee_id', $userId);
                });
            });
        }

        $mainTasks = $query->get();

        // --- Expand tasks + subtasks into flat rows (same pattern as projectsWithTasks) ---
        $rows = [];
        foreach ($mainTasks as $task) {
            if ($task->subTasks->isNotEmpty()) {
                foreach ($task->subTasks as $sub) {
                    $rows[] = [
                        'project' => $task->project,
                        'task' => $task,
                        'sub_task' => $sub,
                    ];
                }
            } else {
                $rows[] = [
                    'project' => $task->project,
                    'task' => $task,
                    'sub_task' => null,
                ];
            }
        }

        // --- Paginate the flat rows ---
        $total = count($rows);
        $offset = ($page - 1) * $perPage;
        $paginated = array_slice($rows, $offset, $perPage);

        // --- Summary counts for the date (before pagination) ---
        $uniqueTaskIds = collect($rows)->pluck('task.id')->unique()->count();
        $uniqueSubIds = collect($rows)->filter(fn($r) => $r['sub_task'] !== null)
            ->pluck('sub_task.id')->unique()->count();
        $uniqueProjectIds = collect($rows)->pluck('project.id')->unique()->count();

        return response()->json([
            'date' => $targetDate->toDateString(),   // "2026-05-19"
            'summary' => [
                'total_projects' => $uniqueProjectIds,
                'total_tasks' => $uniqueTaskIds,
                'total_sub_tasks' => $uniqueSubIds,
                'total_rows' => $total,                   // expanded row count
            ],
            'data' => $paginated,
            'current_page' => (int) $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => (int) ceil($total / $perPage),
            'has_more' => ($offset + $perPage) < $total,
        ]);
    }

    public function store(Request $request)
    {

        $user = Auth::user();

        if ($user->employee_type != "Manager" && $user->employee_type != "Supervisor" && $user->employee_type != "Executive") {

            return response()->json(['message' => 'Unauthorized'], 403);

        }

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

            if ($user) {

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

        $user = Auth::user();

        /*

        if($user->employee_type != "Manager"){

            return response()->json(['message' => 'Unauthorized'], 403);

        } */


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

        if ($project->due_date != $request->due_date) {

            dueChangedNotification($id, $request->due_date, $type = "project_due_date_updated");

        }

        $project->update($validated);

        return response()->json([
            'message' => 'Project updated successfully.',
            'project' => $project,
        ]);
    }

    public function destroy($id)
    {

        $user = Auth::user();

        if ($user->employee_type != "Manager" && $user->employee_type != "Supervisor" && $user->employee_type != "Executive") {

            return response()->json(['message' => 'Unauthorized'], 403);

        }


        $project = Project::findOrFail($id);
        $project->delete();

        return response()->json([
            'message' => 'Project deleted successfully.',
        ]);
    }


}
