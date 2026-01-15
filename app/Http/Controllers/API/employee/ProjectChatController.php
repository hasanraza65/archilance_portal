<?php

namespace App\Http\Controllers\API\employee;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ProjectChat;
use App\Models\ProjectChatAttachment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use App\Models\User;
use App\Models\Project;
use App\Models\ProjectAssignee;
use App\Services\OneDriveService;


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
            'allowed_customer' => $request->allowed_customer ?? 0
        ]);

        // Handle attachments
        $attachments = [];

        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {

                $path = 'project_chat_attachments/' . $chat->id . '/' . uniqid() . '_' . $file->getClientOriginalName();

                // Upload to OneDrive
                app(OneDriveService::class)->upload(
                    $path,
                    file_get_contents($file->getRealPath())
                );

                ProjectChatAttachment::create([
                    'chat_id'   => $chat->id,
                    'user_id'   => Auth::id(),
                    'file_path' => $path,
                    'file_type' => $file->getClientMimeType(),
                    'file_size' => $file->getSize(),
                    'file_name' => $file->getClientOriginalName(),
                ]);

                // ✅ Use OneDrive direct download URL for email
                $attachments[] = app(OneDriveService::class)->getDirectFileUrl($path);
            }
        }



        //send email 

        $project = Project::find($request->project_id);

        $project_assignees = ProjectAssignee::where('project_id',$request->project_id)->get();

        $admins = User::where('user_role',2)->get();

        foreach($project_assignees as $assignee){

            if($assignee->employee_id != Auth::user()->id){
                
                $receiver = User::find($assignee->employee_id);
           
                $receiver_email = $receiver->email;
                $receiver_name = $receiver->name;
                $sender_name = Auth::user()->name;
                $message_text = $request->message;
                $project_title = $project->project_name;
                $project_id = $project->id;

                \Mail::send(
                    'mails.new-project-message',
                    compact(['message_text', 'project_title', 'project_id']),
                    function ($message) use ($receiver_email, $receiver_name, $sender_name, $attachments) {
                        $message
                            ->from("info@archilance.net", $sender_name)
                            ->to($receiver_email)
                            ->subject($sender_name . ' messaged you - Archilance LLC');

                        // Attach uploaded files
                        foreach ($attachments as $filePath) {
                            if (file_exists($filePath)) {
                                $message->attach($filePath);
                            }
                        }
                    }
                );

            }

        }


        foreach($admins as $assignee){

            if($assignee->id != Auth::user()->id){

                $receiver = User::find($assignee->id);
           
                $receiver_email = $receiver->email;
                $receiver_name = $receiver->name;
                $sender_name = Auth::user()->name;
                $message_text = $request->message;
                $project_title = $project->project_name;
                $project_id = $project->id;

                \Mail::send(
                    'mails.new-project-message',
                    compact(['message_text', 'project_title', 'project_id']),
                    function ($message) use ($receiver_email, $receiver_name, $sender_name, $attachments) {
                        $message
                            ->from("info@archilance.net", $sender_name)
                            ->to($receiver_email)
                            ->subject($sender_name . ' messaged you - Archilance LLC');

                        // Attach uploaded files
                        foreach ($attachments as $filePath) {
                            if (file_exists($filePath)) {
                                $message->attach($filePath);
                            }
                        }
                    }
                );

            }

        }



        //ending send email

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

                $path = 'project_chat_attachments/' . $chat->id . '/' . uniqid() . '_' . $file->getClientOriginalName();

                // Upload to OneDrive
                app(OneDriveService::class)->upload(
                    $path,
                    file_get_contents($file->getRealPath())
                );

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
            ->where('allowed_customer',0)
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json(['chats' => $chats]);
    }
    
    public function showWithCustomer($projectId)
    {
        $chats = ProjectChat::where('project_id', $projectId)
            ->with(['attachments','sender'])
            ->where('allowed_customer',1)
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