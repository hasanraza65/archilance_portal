<?php

namespace App\Http\Controllers\API\customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\TaskAssignee;
use Auth;
use App\Models\CustomerTeam;
use App\Models\WorkSession;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;


class ProjectController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        if ($user->user_role == 5) {
            // TEAM MEMBER

            // Get all customer IDs linked to this team member via email
            $customerIds = CustomerTeam::where('email', $user->email)
                ->where('status', 'Approved')
                ->pluck('customer_id');

            $projects = Project::latest()
                ->with(['customer', 'projectAssignees', 'projectAssignees.user'])
                ->whereIn('customer_id', $customerIds)
                ->get();
        } else {
            // CUSTOMER

            $projects = Project::latest()
                ->with(['customer', 'projectAssignees', 'projectAssignees.user'])
                ->where('customer_id', $user->id)
                ->get();
        }

        return response()->json($projects);
    }


    public function projectsWithTasks(Request $request)
    {
        $user = Auth::user();

        // Step 1: Get customer IDs for this user (handles both customers & team members)
        if ($user->user_role == 5) {
            // TEAM MEMBER → fetch related customer IDs
            $customerIds = CustomerTeam::where('email', $user->email)
                ->where('status', 'Approved')
                ->pluck('customer_id');
        } else {
            // CUSTOMER → just their own ID
            $customerIds = [$user->id];
        }

        // Step 2: Build query for tasks related to those customers
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
            'subTasks.attachments',
        ])
            ->whereNull('parent_task_id') // Only main tasks
            ->whereHas('project', function ($q) use ($customerIds) {
                $q->whereIn('customer_id', $customerIds);
            })
            ->latest();

        // Step 3: Filter by task_status if provided
        if ($request->filled('task_status')) {
            $query->where('task_status', $request->task_status);
        }

        // Step 4: Build result array (with or without sub-tasks)
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

        return response()->json($result);
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
               //$netSeconds = $sessionDuration;
               
               
               
               
    
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
                
                
                if($session->task_id == 433){
                    
                       // \Log::info('session start date '.$session->start_date);
                       // \Log::info('session end date '.$session->end_date);
                        
                      //  \Log::info('session start time '.$session->start_time);
                      //  \Log::info('session end time '.$session->end_time);
                        
                       // \Log::info('session duration '.$sessionDuration);
                      //  \Log::info('idle duration '.$adjustmentSeconds);
                        
                       // \Log::info('netSeconds '.$netSeconds);
                        
                     //    \Log::info('total seconds '.$totalSeconds);
                    
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
}
