<?php

namespace App\Http\Controllers\API\employee;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Auth;
use App\Models\TaskBrief;
use App\Models\BriefAttachment;
use Illuminate\Support\Facades\Storage;

class TaskBriefController extends Controller
{
    public function index(){

        $data = TaskBrief::latest()->with('attachments')->get();
        return response()->json($data);

    }

    public function store(Request $request)
    {
        $request->validate([
            'task_id' => 'required|exists:project_tasks,id',
            'brief_description' => 'nullable|string|max:255',
            'brief_date' => 'nullable|date',
            'attachments.*' => 'nullable|file|max:10240', // each file max 10MB
        ]);

        $brief = TaskBrief::create([
            'task_id' => $request->task_id,
            'created_by' => auth()->id(),
            'brief_description' => $request->brief_description,
            'brief_date' => $request->brief_date,
        ]);

        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $path = $file->store('brief_attachments', 'public'); // saves to storage/app/public/task_attachments

                BriefAttachment::create([
                    'brief_id' => $brief->id,
                    'created_by' => auth()->id(),
                    'file_path' => $path,
                    'file_type' => $file->getClientMimeType(),
                    'file_size' => $file->getSize(),
                    'file_name' => $file->getClientOriginalName(),
                ]);
            }
        }

        briefAddedNotification($request->task_id, "task_brief_added");

        return response()->json(['message' => 'Project Brief created successfully.', 'brief' => $brief]);
    }

    public function show($id)
    {
        $task = TaskBrief::with(['attachments'])->findOrFail($id);
        return response()->json($task);
    }

    public function update(Request $request, $id)
    {
        $brief = TaskBrief::findOrFail($id);

        $request->validate([
            'brief_description' => 'string|max:255',
            'brief_date'        => 'nullable|date',
            'attachments.*'     => 'nullable|file|max:10240', // Optional new attachments
            'delete_attachments'=> 'nullable|array',
            'delete_attachments.*' => 'integer|exists:brief_attachments,id',
        ]);

        // Update brief fields
        $brief->update($request->only([
            'brief_description',
            'brief_date',
        ]));

        // Handle new file uploads (optional addition)
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $path = $file->store('brief_attachments', 'public');

                BriefAttachment::create([
                    'brief_id'   => $brief->id,
                    'created_by' => auth()->id(),
                    'file_path'  => $path,
                    'file_type'  => $file->getClientMimeType(),
                    'file_size'  => $file->getSize(),
                    'file_name'  => $file->getClientOriginalName(),
                ]);
            }
        }

        // Handle file deletions (if any)
        if ($request->has('delete_attachments')) {
            foreach ($request->delete_attachments as $attachmentId) {
                $attachment = BriefAttachment::where('brief_id', $brief->id)->find($attachmentId);
                if ($attachment) {
                    Storage::disk('public')->delete($attachment->file_path); // delete physical file
                    $attachment->delete(); // delete DB record
                }
            }
        }

        return response()->json([
            'message' => 'Project Brief updated successfully.',
            'brief'   => $brief->load('attachments') // Optional: include attachments
        ]);
    }

     public function destroy($id)
    {
        $project = TaskBrief::findOrFail($id);
        $project->delete();

        return response()->json([
            'message' => 'Project Brief deleted successfully.',
        ]);
    }
}

