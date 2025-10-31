<?php

namespace App\Http\Controllers\API\employee;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\TaskComment;
use App\Models\TaskCommentAttachment;
use App\Models\TaskCommentReadStatus;
use Auth;
use Illuminate\Support\Facades\Storage;

class TaskCommentController extends Controller
{
    // ✅ Get all comments for a task
    public function index(Request $request)
    {
        $userId = Auth::id();
    
        $query = TaskComment::with(['sender'])
            ->withCount('replies');
    
        if ($request->has('task_id')) {
            $query->where('task_id', $request->task_id)
                ->whereNull('reply_to');
        }
    
        // 1. Order by latest first
        $comments = $query->with(['commentAttachments','replies','replies.sender'])->orderBy('created_at', 'desc')->paginate(10);
    
        // 2. Add custom field
        $comments->getCollection()->transform(function ($comment) use ($userId) {
            $comment->is_read = $comment->isReadBy($userId);
            return $comment;
        });
    
        // 3. Reverse items so messages go oldest -> newest within this page
        $comments->setCollection($comments->getCollection()->reverse()->values());
    
        return response()->json($comments);
    }

    // ✅ Store a new comment or reply
    public function store(Request $request)
    {
        $request->validate([
            'task_id'          => 'required|exists:project_tasks,id',
            'comment_message'  => 'nullable|string',
            'reply_to'         => 'nullable|exists:task_comments,id',
            'attachments.*'    => 'nullable|file|max:10240', // Up to 10MB per file
        ]);

        $comment = TaskComment::create([
            'task_id'         => $request->task_id,
            'comment_message' => $request->comment_message,
            'sender_id'       => Auth::id(),
            'reply_to'        => $request->reply_to,
        ]);

        // Handle file uploads
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $path = $file->store('task_comment_attachments', 'public');

                TaskCommentAttachment::create([
                    'comment_id' => $comment->id,
                    'user_id'    => Auth::id(),
                    'file_path'  => $path,
                    'file_type'  => $file->getClientMimeType(),
                    'file_size'  => $file->getSize(),
                    'file_name' => $file->getClientOriginalName(),
                ]);
            }
        }

        return response()->json(['message' => 'Comment added.', 'comment' => $comment]);
    }

    // ✅ Show single comment (with replies)
    public function show(Request $request, $id)
    {
        $userId = Auth::user()->id ?? null;

        $comment = TaskComment::with(['sender', 'replies', 'replies.commentAttachments', 'replies.sender' , 'commentAttachments'])->findOrFail($id);

        // Mark as read (optional)
        if ($userId) {
            TaskCommentReadStatus::updateOrCreate(
                ['receiver_id' => $userId, 'comment_id' => $comment->id],
                ['is_read' => true]
            );
        }

        $comment->is_read = $comment->isReadBy($userId);

        return response()->json($comment);
    }
    // ✅ Update a comment (only if sender is same)
    public function update(Request $request, $id)
    {
        
        \Log::info($request->all());
        
        $comment = TaskComment::findOrFail($id);

        $request->validate([
            'comment_message'    => 'nullable|string',
            'attachments.*'      => 'nullable|file|max:10240', // Optional new attachments
            'delete_attachments' => 'nullable|array',          // Array of IDs to delete
        ]);

        // Update comment message
        $comment->update([
            'comment_message' => $request->comment_message,
        ]);
        
       

        // Handle new file uploads
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $path = $file->store('task_comment_attachments', 'public');

                TaskCommentAttachment::create([
                    'comment_id' => $comment->id,
                    'user_id'    => auth()->id(),
                    'file_path'  => $path,
                    'file_type'  => $file->getClientMimeType(),
                    'file_size'  => $file->getSize(),
                    'file_name' => $file->getClientOriginalName(),
                ]);
            }
        }

        // Handle deletions of existing attachments
        if ($request->has('delete_attachments')) {
            foreach ($request->delete_attachments as $attachmentId) {
                $attachment = TaskCommentAttachment::where('comment_id', $comment->id)->find($attachmentId);
                if ($attachment) {
                    Storage::disk('public')->delete($attachment->file_path); // Delete file
                    $attachment->delete(); // Delete DB record
                }
            }
        }

        return response()->json([
            'message' => 'Comment updated successfully.',
            'comment' => $comment->load('commentAttachments') // Optional: include attachments in response
        ]);
    }

    // ✅ Delete a comment (and its replies)
    public function destroy($id)
    {
        $comment = TaskComment::findOrFail($id);

        // Delete replies as well
        TaskComment::where('reply_to', $comment->id)->delete();

        $comment->delete();

        return response()->json(['message' => 'Comment deleted.']);
    }

    public function markAllAsRead(Request $request)
    {
        $userId = Auth::user()->id;
        $taskId = $request->task_id;

        $comments = TaskComment::where('task_id', $taskId)
            ->whereNull('reply_to')
            ->pluck('id');

        foreach ($comments as $commentId) {
            TaskCommentReadStatus::updateOrCreate(
                ['receiver_id' => $userId, 'comment_id' => $commentId],
                ['is_read' => true]
            );
        }

        return response()->json(['message' => 'All comments marked as read']);
    }

    public function markAllRepliesAsRead(Request $request)
    {
        $userId = Auth::user()->id;
        $commentId = $request->comment_id;

        $comments = TaskComment::where('reply_to', $commentId)
            ->pluck('id');

        foreach ($comments as $commentId) {
            TaskCommentReadStatus::updateOrCreate(
                ['receiver_id' => $userId, 'comment_id' => $commentId],
                ['is_read' => true]
            );
        }

        return response()->json(['message' => 'All comments marked as read']);
    }
}

