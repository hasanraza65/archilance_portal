<?php

namespace App\Http\Controllers\API\customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\ProjectTask;
use App\Models\TaskAttachment;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Models\WorkSession;

class ProjectTaskController extends Controller
{
    public function index(Request $request)
    {
        $query = ProjectTask::with(['assignees', 'assignees.user', 'comments', 'creator', 'attachments']);

        if ($request->has('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        $tasks = $query->whereNull('parent_task_id')->get();

        return response()->json($tasks);
    }

    public function show($id)
    {
        $task = ProjectTask::with([
            'assignees', 
            'assignees.user', 
            'comments', 
            'comments.sender',
            'comments.commentAttachments', 
            'subTasks', 
            'subTasks.creator', 
            'attachments', 
            'allBriefs', 
            'allBriefs.attachments'
        ])->findOrFail($id);

        // Calculate working hours for each assignee
        $assigneesWithHours = [];
        
        foreach ($task->assignees as $assignee) {
            $employeeId = $assignee->employee_id;
            
            // Calculate total working hours for this employee on this task
            $totalHours = $this->calculateEmployeeTaskHours($employeeId, $task->id);
            
            $assigneesWithHours[] = [
                'assignee' => $assignee,
                'user' => $assignee->user,
                'total_working_hours' => $totalHours,
                'total_working_hours_formatted' => $this->formatHours($totalHours)
            ];
        }
    
        // Add the calculated hours to the task response
        $task->assignees_with_hours = $assigneesWithHours;
        
        return response()->json($task);
    }


     private function calculateEmployeeTaskHours($employeeId, $taskId)
    {
       // \Log::info("Calculating hours for employee: $employeeId, task: $taskId");
        
        $sessions = WorkSession::where('user_id', $employeeId)
            ->where('task_id', $taskId)
            ->get();

       // \Log::info("Found " . $sessions->count() . " work sessions");

        $totalSeconds = 0;

        foreach ($sessions as $session) {
          //  \Log::info("Processing session ID: " . $session->id);
           // \Log::info("Session data - Start: {$session->start_date} {$session->start_time}, End: {$session->end_date} {$session->end_time}");

            try {
                // Parse start time
                $sessionStart = Carbon::parse($session->start_date . ' ' . $session->start_time);
                
                // Parse end time (handle running sessions and null end dates)
                if (is_null($session->end_time)) {
                    $sessionEnd = now();
                  //  \Log::info("Session is still running, using current time");
                } else {
                    $endDate = $session->end_date ?? $session->start_date;
                    $sessionEnd = Carbon::parse($endDate . ' ' . $session->end_time);
                }

               // \Log::info("Parsed times - Start: " . $sessionStart->format('Y-m-d H:i:s') . 
                  //      ", End: " . $sessionEnd->format('Y-m-d H:i:s'));

                // Calculate duration - ensure it's positive
                $sessionDuration = $sessionEnd->diffInSeconds($sessionStart);
               // \Log::info("Raw session duration: $sessionDuration seconds");

                // If duration is negative, swap the times (this handles cases where end time is before start time)
                if ($sessionDuration < 0) {
                  //  \Log::warning("Negative duration detected, swapping start and end times");
                    $sessionDuration = $sessionStart->diffInSeconds($sessionEnd);
                  //  \Log::info("Corrected session duration: $sessionDuration seconds");
                }

                // Subtract adjustments
                $adjustmentSeconds = 0;
                $adjustments = DB::table('session_time_adjustments')
                    ->where('session_id', $session->id)
                    ->get();

              //  \Log::info("Found " . $adjustments->count() . " adjustments for session");

                foreach ($adjustments as $adj) {
                    if (empty($adj->start_time) || empty($adj->end_time)) {
                      //  \Log::warning("Invalid adjustment times for session: " . $session->id);
                        continue;
                    }
                    
                    try {
                        $adjStart = Carbon::parse($adj->start_time);
                        $adjEnd = Carbon::parse($adj->end_time);
                        $adjustmentDuration = $adjEnd->diffInSeconds($adjStart);
                        
                        // Ensure adjustment duration is positive
                        if ($adjustmentDuration < 0) {
                            $adjustmentDuration = $adjStart->diffInSeconds($adjEnd);
                        }
                        
                        $adjustmentSeconds += $adjustmentDuration;
                       // \Log::info("Adjustment duration: $adjustmentDuration seconds");
                    } catch (\Exception $e) {
                     //   \Log::error("Error parsing adjustment times: " . $e->getMessage());
                        continue;
                    }
                }

                $netSeconds = $sessionDuration - $adjustmentSeconds;
               // \Log::info("Net seconds after adjustments: $netSeconds");
                
                if ($netSeconds > 0) {
                    $totalSeconds += $netSeconds;
                  //  \Log::info("Added to total: $netSeconds seconds");
                } else {
                  //  \Log::warning("Net seconds is negative or zero: $netSeconds, skipping");
                }

            } catch (\Exception $e) {
             //   \Log::error("Error processing session {$session->id}: " . $e->getMessage());
                continue;
            }
        }

       // \Log::info("Final total seconds: $totalSeconds");
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
