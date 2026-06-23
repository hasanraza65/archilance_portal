<?php

namespace App\Http\Controllers\api\admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\TaskComment;
use App\Models\ProjectTask;
use App\Models\ProjectTask;
use App\Models\TaskCommentAttachment;
use App\Models\TaskCommentReadStatus;
use Auth;
use Illuminate\Support\Facades\Storage;
use App\Services\OneDriveService;
use App\Models\TaskAssignee;
use App\Models\User;
use GuzzleHttp\Client;

class TaskCommentController extends Controller
{
    // ✅ Get all comments for a task
    public function index(Request $request)
{
    $userId = Auth::id();

    // 🔹 Base query (normal messages only)
    $query = TaskComment::with(['sender'])
        ->withCount('replies')
        ->where('allowed_customer', 0)
        ->whereNull('reply_to')
        ->where('is_pinned', 0); // ❗ exclude pinned from main list

    if ($request->has('task_id')) {
        $query->where('task_id', $request->task_id);
    }

    // 🔹 Pagination (normal messages)
    $comments = $query->with(['commentAttachments', 'replies', 'replies.sender'])
        ->orderBy('created_at', 'desc')
        ->paginate(10);

    // 🔹 add read status
    $comments->getCollection()->transform(function ($comment) use ($userId) {
        $comment->is_read = $comment->isReadBy($userId);
        return $comment;
    });

    // 🔹 reverse normal messages (old → new)
    $normalMessages = $comments->getCollection()->reverse()->values();

    // 🔹 pinned messages (NOT paginated)
    $pinnedMessages = TaskComment::with([
            'sender',
            'commentAttachments',
            'replies',
            'replies.sender'
        ])
        ->where('task_id', $request->task_id)
        ->where('allowed_customer', 0)
        ->where('is_pinned', 1)
        ->orderBy('pinned_at', 'desc')
        ->get();

    // 🔥 FINAL MERGE (pinned first)
    $finalData = $pinnedMessages->merge($normalMessages);

    $comments->setCollection($finalData);

    return response()->json($comments);
}

    public function indexWithCustomer(Request $request)
    {
        $userId = Auth::id();

        $query = TaskComment::with(['sender'])
            ->withCount('replies');

        if ($request->has('task_id')) {
            $query->where('task_id', $request->task_id)
                ->whereNull('reply_to');
        }

        // 1. Order by latest first
        $comments = $query->with(['commentAttachments', 'replies', 'replies.sender'])
            ->where('allowed_customer', 1)
            ->orderBy('created_at', 'desc')
            ->paginate(10);

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
            'attachments.*'    => 'nullable|file|max:10240',
            'tag_user'         => 'nullable|array',
            'tag_user.*'       => 'exists:users,id',
        ]);
    
        $taggedUsers = $request->tag_user ?? [];
    
        $comment = TaskComment::create([
            'task_id'          => $request->task_id,
            'comment_message'  => $request->comment_message,
            'sender_id'        => Auth::id(),
            'reply_to'         => $request->reply_to,
            'allowed_customer' => $request->allowed_customer ?? 0,
            'tagged_users'     => $taggedUsers,   // stored as JSON
        ]);
    
        // Handle file uploads
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $path = 'task_comment_attachments/' . $comment->id . '/' . uniqid() . '_' . $file->getClientOriginalName();
    
                app(OneDriveService::class)->upload(
                    $path,
                    file_get_contents($file->getRealPath())
                );
    
                TaskCommentAttachment::create([
                    'comment_id' => $comment->id,
                    'user_id'    => Auth::id(),
                    'file_path'  => $path,
                    'file_type'  => $file->getClientMimeType(),
                    'file_size'  => $file->getSize(),
                    'file_name'  => $file->getClientOriginalName(),
                ]);
            }
        }
    
        $notifyMessage = $request->comment_message ?? "Sent an attachment";
        $senderName    = Auth::user()->name;
    
        // ✅ Notify all task assignees (except the commenter)
        $assignees = TaskAssignee::where('task_id', $request->task_id)
            ->where('employee_id', '!=', Auth::id())
            ->with('user')
            ->get();
    
        foreach ($assignees as $assignee) {
            if (!$assignee->user || !$assignee->user->fcm_token) continue;
    
            // Check if this assignee is also tagged — give them 'sos' type
            $isTagged = in_array($assignee->employee_id, $taggedUsers);
    
            $this->sendFcmNotification(
                $assignee->user->fcm_token,
                $isTagged ? "You were mentioned in a comment" : "New Comment on Task",
                $senderName . ": " . $notifyMessage,
                [
                    'type'        => $isTagged ? 'sos' : 'normal',
                    'notify_type' => 'chat',
                    'chat_type'   => 'task',
                    'ref_id'      => (string) $request->task_id,
                    'comment_id'  => (string) $comment->id,
                    'message'     => $notifyMessage,
                ]
            );
        }
    
        // ✅ Notify tagged users who are NOT assignees (so they don't get double-notified)
        $assigneeIds   = $assignees->pluck('employee_id')->toArray();
        $taggedNonAssignees = array_diff($taggedUsers, $assigneeIds, [Auth::id()]);
    
        if (!empty($taggedNonAssignees)) {
            $taggedUsersData = User::whereIn('id', $taggedNonAssignees)
                ->whereNotNull('fcm_token')
                ->get();
    
            foreach ($taggedUsersData as $user) {
                $this->sendFcmNotification(
                    $user->fcm_token,
                    "You were mentioned in a comment",
                    $senderName . ": " . $notifyMessage,
                    [
                        'type'        => 'sos',
                        'notify_type' => 'chat',
                        'chat_type'   => 'task',
                        'ref_id'      => (string) $request->task_id,
                        'comment_id'  => (string) $comment->id,
                        'message'     => $notifyMessage,
                    ]
                );
            }
        }
    
        return response()->json(['message' => 'Comment added.', 'comment' => $comment]);
    }


   protected function sendFcmNotification($token, $title, $body, $dataPayload = [])
{
    try {
        \Log::info('FCM START');

        $serviceAccountPath = storage_path('firebase/firebase-key.json');
        $jsonKey = json_decode(file_get_contents($serviceAccountPath), true);

        $client = new Client();

        /*
        |--------------------------------------------------------------------------
        | 1. Generate OAuth Token
        |--------------------------------------------------------------------------
        */
        $now = time();
        $jwtHeader = base64_encode(json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT'
        ]));

        $jwtClaim = base64_encode(json_encode([
            'iss'   => $jsonKey['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'exp'   => $now + 3600,
            'iat'   => $now
        ]));

        $unsignedJwt = $jwtHeader . '.' . $jwtClaim;

        openssl_sign($unsignedJwt, $signature, $jsonKey['private_key'], 'sha256');
        $jwt = $unsignedJwt . '.' . base64_encode($signature);

        $response = $client->post('https://oauth2.googleapis.com/token', [
            'form_params' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]
        ]);

        $accessToken = json_decode((string) $response->getBody(), true)['access_token'];

        \Log::info('FCM TOKEN GENERATED');

        /*
        |--------------------------------------------------------------------------
        | 2. Build Data Payload
        | — $dataPayload values override the defaults when provided
        |--------------------------------------------------------------------------
        */
        $defaultData = [
            'type'        => 'normal',
            'notify_type' => 'chat',
            'chat_type'   => 'user',
            'ref_id'      => (string) Auth::id(),
            'message'     => (string) $body,
        ];

        $mergedData = collect(array_merge($defaultData, $dataPayload))
            ->map(fn($v) => (string) $v)
            ->toArray();

        /*
        |--------------------------------------------------------------------------
        | 3. Send Notification
        |--------------------------------------------------------------------------
        */
        $projectId = $jsonKey['project_id'];

        $fcmResponse = $client->post(
            "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send",
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'message' => [
                        'token' => $token,

                        'notification' => [
                            'title' => $title,
                            'body'  => $body,
                        ],

                        'android' => [
                            'notification' => [
                                'channel_id' => 'archilance_notification_channel',
                                'sound'      => 'notification_sound',
                            ],
                        ],

                        'apns' => [
                            'payload' => [
                                'aps' => [
                                    'sound' => 'notification_sound.wav',
                                ],
                            ],
                        ],

                        'data' => $mergedData,
                    ]
                ]
            ]
        );

        \Log::info('FCM SENT SUCCESS', [
            'response' => (string) $fcmResponse->getBody()
        ]);

    } catch (\Exception $e) {
        \Log::error('FCM ERROR: ' . $e->getMessage());
    }
}
    
   

    // ✅ Show single comment (with replies)
    public function show(Request $request, $id)
    {
        $userId = Auth::user()->id ?? null;

        $comment = TaskComment::with(['sender', 'replies', 'replies.commentAttachments', 'replies.sender', 'commentAttachments'])
            ->findOrFail($id);

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
            'comment_message' => 'nullable|string',
            'attachments.*' => 'nullable|file|max:10240', // Optional new attachments
            'delete_attachments' => 'nullable|array',          // Array of IDs to delete
        ]);

        // Update comment message
        $comment->update([
            'comment_message' => $request->comment_message,
        ]);



        // Handle new file uploads
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {

                $path = 'task_comment_attachments/' . $comment->id . '/' . uniqid() . '_' . $file->getClientOriginalName();

                // Upload to OneDrive
                app(OneDriveService::class)->upload(
                    $path,
                    file_get_contents($file->getRealPath())
                );

                TaskCommentAttachment::create([
                    'comment_id' => $comment->id,
                    'user_id' => auth()->id(),
                    'file_path' => $path,
                    'file_type' => $file->getClientMimeType(),
                    'file_size' => $file->getSize(),
                    'file_name' => $file->getClientOriginalName(),
                ]);
            }
        }

        // Handle deletions of existing attachments
        if ($request->has('delete_attachments')) {
            foreach ($request->delete_attachments as $attachmentId) {

                $attachment = TaskCommentAttachment::where('comment_id', $comment->id)
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

    public function allTasksChats()
    {
        // 🔹 Internal chats
        $internalTasks = ProjectTask::with([
            'latestInternalComment.sender',
            'latestInternalComment.commentAttachments',
            'pinnedInternalComments.sender',
            'pinnedInternalComments.commentAttachments'
        ])
            ->whereHas('comments', function ($q) {
                $q->where('allowed_customer', 0);
            })
            ->withMax([
                'comments as internal_comments_max_created_at' => function ($q) {
                    $q->where('allowed_customer', 0);
                }
            ], 'created_at')
            ->orderByDesc('internal_comments_max_created_at')
            ->get();

        // 🔹 Customer chats
        $customerTasks = ProjectTask::with([
            'latestCustomerComment.sender',
            'latestCustomerComment.commentAttachments',
            'pinnedCustomerComments.sender',
            'pinnedCustomerComments.commentAttachments'
        ])
            ->whereHas('comments', function ($q) {
                $q->where('allowed_customer', 1);
            })
            ->withMax([
                'comments as customer_comments_max_created_at' => function ($q) {
                    $q->where('allowed_customer', 1);
                }
            ], 'created_at')
            ->orderByDesc('customer_comments_max_created_at')
            ->get();

        return response()->json([
            'internal_tasks' => $internalTasks,
            'customer_tasks' => $customerTasks,
        ]);
    }

    public function pinComment(Request $request)
    {
        $comment = TaskComment::findOrFail($request->comment_id);

        $comment->update([
            'is_pinned' => 1,
            'pinned_at' => now() // optional (if you added column)
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Pinned successfully',
            'data' => $comment
        ]);
    }

    public function unpinComment(Request $request)
    {
        $comment = TaskComment::findOrFail($request->comment_id);

        $comment->update([
            'is_pinned' => 0,
            'pinned_at' => null // optional
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Unpinned successfully'
        ]);
    }
}
