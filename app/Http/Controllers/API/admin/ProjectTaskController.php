<?php

namespace App\Http\Controllers\api\admin;

use App\Http\Controllers\Controller;

use App\Models\ProjectTask;
use App\Models\TaskAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Models\ProjectAssignee;
use App\Models\TaskAssignee;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Models\WorkSession;
use App\Models\Project;
use App\Services\OneDriveService;




class ProjectTaskController extends Controller
{
    // ✅ Get all tasks (optionally filtered by project)
    public function index(Request $request)
    {
        $query = ProjectTask::with(['assignees', 'assignees.user', 'comments', 'creator', 'attachments']);

        if ($request->has('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        $tasks = $query->whereNull('parent_task_id')->get();

        return response()->json($tasks);
    }

    public function allTasks()
    {
        $query = ProjectTask::with(['assignees', 'assignees.user', 'comments', 'creator', 'attachments', 'parentTask'])->get();

        //$tasks = $query->whereNull('parent_task_id')->get();

        return response()->json($query);
    }

    // ✅ Store new task
    public function store(Request $request)
    {
        $request->validate([
            'project_id' => 'required|exists:projects,id',
            'task_title' => 'required|string|max:255',
            'task_status' => 'nullable|string',
            'priority' => 'nullable|string',
            'due_date' => 'nullable|date',
            'parent_task_id' => 'nullable|exists:project_tasks,id',
            'attachments.*' => 'nullable|file|max:10240', // each file max 10MB
        ]);

        // Create the task
        $task = ProjectTask::create([
            'project_id' => $request->project_id,
            'parent_task_id' => $request->parent_task_id,
            'created_by' => auth()->id(),
            'task_title' => $request->task_title,
            'task_description' => $request->task_description,
            'task_status' => $request->task_status ?? 'Backlog',
            'priority' => $request->priority ?? 'Normal',
            'due_date' => $request->due_date,
        ]);

        if ($request->task_status == "Backlog") {
            // Update project status
            $project_data = Project::find($request->project_id);
            if ($project_data) {
                $project_data->status = "Backlog";
                $project_data->update();
            }

            // ✅ Also update parent task status if subtask belongs to a parent
            if (!empty($request->parent_task_id)) {
                $parentTask = ProjectTask::find($request->parent_task_id);
                if ($parentTask) {
                    $parentTask->task_status = "Backlog";
                    $parentTask->update();
                }
            }
        }

        // Handle file attachments
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
        
                $path = 'task_attachments/' . $task->id . '/' . uniqid() . '_' . $file->getClientOriginalName();
        
                app(OneDriveService::class)->upload(
                    $path,
                    file_get_contents($file->getRealPath())
                );
        
                TaskAttachment::create([
                    'task_id'   => $task->id,
                    'user_id'   => auth()->id(),
                    'file_path' => $path,
                    'file_type' => $file->getClientMimeType(),
                    'file_size' => $file->getSize(),
                    'file_name' => $file->getClientOriginalName(),
                ]);
            }
        }

        // Automatically assign task to all users already assigned to the project
        if ($request->parent_task_id == null || $request->parent_task_id == "") {
            $projectAssignees = ProjectAssignee::where('project_id', $request->project_id)->pluck('employee_id');

            foreach ($projectAssignees as $employeeId) {
                TaskAssignee::firstOrCreate([
                    'task_id' => $task->id,
                    'employee_id' => $employeeId,
                ]);
            }
        }


        return response()->json([
            'message' => 'Task created and assigned to project members successfully.',
            'task' => $task,
        ]);
    }

    // ✅ Show single task
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
            'allBriefs.attachments',
            'allNotes'
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

    // Helper method to calculate employee task hours
    private function calculateEmployeeTaskHours($employeeId, $taskId)
    {
        \Log::info("Calculating hours for employee: $employeeId, task: $taskId");

        $sessions = WorkSession::where('user_id', $employeeId)
            ->where('task_id', $taskId)
            ->get();

        \Log::info("Found " . $sessions->count() . " work sessions");

        $totalSeconds = 0;

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

              

                // Calculate duration - ensure it's positive
                $sessionDuration = $sessionEnd->diffInSeconds($sessionStart);
                

                // If duration is negative, swap the times (this handles cases where end time is before start time)
                if ($sessionDuration < 0) {
                   
                    $sessionDuration = $sessionStart->diffInSeconds($sessionEnd);
                    
                }

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
                        $adjustmentDuration = $adjEnd->diffInSeconds($adjStart);

                        // Ensure adjustment duration is positive
                        if ($adjustmentDuration < 0) {
                            $adjustmentDuration = $adjStart->diffInSeconds($adjEnd);
                        }

                        $adjustmentSeconds += $adjustmentDuration;
                      
                    } catch (\Exception $e) {
                        \Log::error("Error parsing adjustment times: " . $e->getMessage());
                        continue;
                    }
                }

                $netSeconds = $sessionDuration - $adjustmentSeconds;
               

                if ($netSeconds > 0) {
                    $totalSeconds += $netSeconds;
                    
                } else {
                   
                }

            } catch (\Exception $e) {
                \Log::error("Error processing session {$session->id}: " . $e->getMessage());
                continue;
            }
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

    // ✅ Update task
    public function update(Request $request, $id)
    {
        $task = ProjectTask::findOrFail($id);
        $task_status_changed = 0;

        $request->validate([
            'task_title' => 'sometimes|required|string|max:255',
            'task_status' => 'nullable|string',
            'priority' => 'nullable|string',
            'due_date' => 'nullable|date',
            'attachments.*' => 'nullable|file|max:10240',
        ]);

        if($task->due_date != $request->due_date){

              dueChangedNotification($id, $request->due_date, $type="task_due_date_updated");

        }

        if ($task->task_status != $request->task_status) {
            $task_status_changed = 1;
        }

        $task->update($request->only([
            'task_title',
            'task_description',
            'task_status',
            'priority',
            'due_date',
            'completed_date',
        ]));

      // Upload new attachments to OneDrive
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {

                $path = 'task_attachments/' . $task->id . '/' . uniqid() . '_' . $file->getClientOriginalName();

                // Upload to OneDrive
                app(OneDriveService::class)->upload(
                    $path,
                    file_get_contents($file->getRealPath())
                );

                TaskAttachment::create([
                    'task_id'   => $task->id,
                    'user_id'   => auth()->id(),
                    'file_path' => $path,
                    'file_type' => $file->getClientMimeType(),
                    'file_size' => $file->getSize(),
                    'file_name' => $file->getClientOriginalName(),
                ]);
            }
        }

        // Delete attachments from OneDrive
        if ($request->has('delete_attachments')) {
            foreach ($request->delete_attachments as $attachmentId) {

                $attachment = TaskAttachment::where('task_id', $task->id)
                    ->where('id', $attachmentId)
                    ->first();

                if ($attachment) {
                    // Delete from OneDrive
                    app(OneDriveService::class)->delete($attachment->file_path);

                    // Delete DB record
                    $attachment->delete();
                }
            }
        }

        // ✅ NEW: Update parent Project (Job) Status based on all child tasks
        $this->updateParentProjectStatus($task->project_id);

        if ($task_status_changed == 1) {
            statusChangedNotification($id, $request->task_status, $type = "task_status_changed");
        }


        return response()->json([
            'message' => 'Task updated successfully.',
            'task' => $task->load('attachments')
        ]);
    }

    private function updateParentProjectStatus($projectId)
    {
        $project = Project::find($projectId);
        if (!$project)
            return;

        $statuses = ProjectTask::where('project_id', $projectId)->pluck('task_status')->toArray();

        $priority = [
            'In Progress' => 1,
            'Backlog' => 2,
            'In-house Review' => 3,
            'Awaiting Info' => 4,
            'On Hold' => 5,
            'Client Review' => 6,
            'Completed' => 7,
        ];

        // ✅ If all statuses are the same → use that status
        if (count(array_unique($statuses)) === 1) {
            $project->status = $statuses[0];
        } else {
            // ✅ Mixed statuses → find highest priority
            $project->status = collect($statuses)
                ->sortBy(fn($s) => $priority[$s] ?? 999)
                ->first();
        }

        $project->save();
    }


    // ✅ Delete task (soft delete if enabled)
    public function destroy($id)
    {
        $task = ProjectTask::findOrFail($id);
        $task->delete();

        return response()->json(['message' => 'Task deleted successfully.']);
    }

    public function changeOrder(Request $request)
    {

        $task = ProjectTask::findOrFail($request->task_id);

        $task->board_order = $request->board_order;
        $task->update();

        return response()->json(['message' => 'Task order updated successfully.']);

    }
}
