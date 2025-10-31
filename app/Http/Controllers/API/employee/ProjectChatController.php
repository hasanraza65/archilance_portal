<?php

namespace App\Http\Controllers\API\employee;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ProjectChat;
use App\Models\ProjectChatAttachment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class ProjectChatController extends Controller
{
    // Send message (admin)
    public function store(Request $request)
    {
        $request->validate([
            'project_id'   => 'required|exists:projects,id',
            'message'      => 'nullable|string',
            'attachments.*'=> 'nullable|file|max:10240', // 10MB
        ]);

        $chat = ProjectChat::create([
            'project_id' => $request->project_id,
            'sender_id'  => Auth::id(),
            'is_admin'   => true, // Admin message
            'message'    => $request->message,
        ]);

        // Handle attachments
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $path = $file->store('project_chat_attachments', 'public');

                ProjectChatAttachment::create([
                    'chat_id'   => $chat->id,
                    'user_id'   => Auth::id(),
                    'file_path' => $path,
                    'file_type' => $file->getClientMimeType(),
                    'file_size' => $file->getSize(),
                    'file_name' => $file->getClientOriginalName(),
                ]);
            }
        }

        return response()->json([
            'message' => 'Message sent successfully.',
            'chat'    => $chat->load('attachments'),
        ]);
    }

    // Update admin message
    public function update(Request $request, $id)
    {
        $chat = ProjectChat::findOrFail($id);

        if ($chat->sender_id != Auth::id() || !$chat->is_admin) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'message'            => 'nullable|string',
            'attachments.*'      => 'nullable|file|max:10240',
            'delete_attachments' => 'nullable|array',
        ]);

        $chat->update(['message' => $request->message]);

        // Add attachments
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $path = $file->store('project_chat_attachments', 'public');

                ProjectChatAttachment::create([
                    'chat_id'   => $chat->id,
                    'user_id'   => Auth::id(),
                    'file_path' => $path,
                    'file_type' => $file->getClientMimeType(),
                    'file_size' => $file->getSize(),
                    'file_name' => $file->getClientOriginalName(),
                ]);
            }
        }

        // Delete attachments
        if ($request->has('delete_attachments')) {
            foreach ($request->delete_attachments as $attachmentId) {
                $attachment = ProjectChatAttachment::where('chat_id', $chat->id)
                    ->find($attachmentId);

                if ($attachment) {
                    Storage::disk('public')->delete($attachment->file_path);
                    $attachment->delete();
                }
            }
        }

        return response()->json([
            'message' => 'Message updated.',
            'chat'    => $chat->load('attachments')
        ]);
    }

    // Get all chats for a project (admin view)
    public function show($projectId)
    {
        $chats = ProjectChat::where('project_id', $projectId)
            ->with(['attachments','sender'])
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json(['chats' => $chats]);
    }

    // Delete message
    public function destroy($id)
    {
        $chat = ProjectChat::findOrFail($id);

        if ($chat->sender_id != Auth::id() || !$chat->is_admin) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        foreach ($chat->attachments as $attachment) {
            Storage::disk('public')->delete($attachment->file_path);
            $attachment->delete();
        }

        $chat->delete();

        return response()->json(['message' => 'Message deleted.']);
    }
}