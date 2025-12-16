<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Chat;
use App\Models\ChatAttachment;
use App\Models\ChatReaction;
use App\Models\ChatReadStatus;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use App\Models\User;

class ChatController extends Controller
{
    // Send Message
    public function store(Request $request)
    {
        $request->validate([
            'receiver_id'   => 'required|exists:users,id',
            'message'       => 'nullable|string',
            'attachments.*' => 'nullable|file|max:10240', // 10MB
        ]);

        $chat = Chat::create([
            'sender_id'   => Auth::id(),
            'receiver_id' => $request->receiver_id,
            'message'     => $request->message,
            'reply_to' => $request->reply_to
        ]);

        // Handle attachments
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $path = $file->store('chat_attachments', 'public');

                ChatAttachment::create([
                    'chat_id'   => $chat->id,
                    'user_id'   => Auth::id(),
                    'file_path' => $path,
                    'file_type' => $file->getClientMimeType(),
                    'file_size' => $file->getSize(),
                    'file_name' => $file->getClientOriginalName(),
                ]);
            }
        }
        
        
         //send email 

        $receiver = User::find($request->receiver_id);

        $receiver_email = $receiver->email;
        $receiver_name = $receiver->name;
        $sender_name = Auth::user()->name;
        $message_text = $request->message;

        \Mail::send(
            'mails.new-message',
            compact(['message_text']),
            function ($message) use ($receiver_email, $receiver_name, $sender_name) {


                $message
                    ->from("info@archilance.net", $sender_name)
                    ->to($receiver_email)
                    ->subject($sender_name.' messaged you - Archilance LLC');  // Attach the PDF file
            }
        );

        //ending send email

        return response()->json(['message' => 'Message sent.', 'chat' => $chat->load('attachments')]);
    }

    // Update message
    public function update(Request $request, $id)
    {
        $chat = Chat::findOrFail($id);

        if ($chat->sender_id != Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'message'           => 'nullable|string',
            'attachments.*'     => 'nullable|file|max:10240',
            'delete_attachments'=> 'nullable|array',
        ]);

        $chat->update([
            'message' => $request->message,
        ]);

        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $path = $file->store('chat_attachments', 'public');

                ChatAttachment::create([
                    'chat_id'   => $chat->id,
                    'user_id'   => Auth::id(),
                    'file_path' => $path,
                    'file_type' => $file->getClientMimeType(),
                    'file_size' => $file->getSize(),
                    'file_name' => $file->getClientOriginalName(),
                ]);
            }
        }

        if ($request->has('delete_attachments')) {
            foreach ($request->delete_attachments as $attachmentId) {
                $attachment = ChatAttachment::where('chat_id', $chat->id)->find($attachmentId);
                if ($attachment) {
                    Storage::disk('public')->delete($attachment->file_path);
                    $attachment->delete();
                }
            }
        }

        return response()->json(['message' => 'Message updated.', 'chat' => $chat->load('attachments')]);
    }

    // Add reaction
    public function addReaction(Request $request)
    {
        $request->validate([
            'chat_id' => 'required|exists:chats,id',
            'reaction' => 'required|string',
        ]);

        $reaction = ChatReaction::updateOrCreate(
            [
                'chat_id' => $request->chat_id,
                'user_id' => Auth::id(),
            ],
            [
                'reaction' => $request->reaction,
            ]
        );

        return response()->json(['message' => 'Reaction updated.', 'reaction' => $reaction]);
    }


    public function removeReaction(Request $request)
    {
        $request->validate([
            'chat_id' => 'required|exists:chats,id',
        ]);

        $deleted = ChatReaction::where('chat_id', $request->chat_id)
            ->where('user_id', Auth::id())
            ->delete();

        if ($deleted) {
            return response()->json(['message' => 'Reaction removed.']);
        } else {
            return response()->json(['message' => 'No reaction found to remove.'], 404);
        }
    }

    // Mark message as read
    public function markRead(Request $request)
    {
        $request->validate([
            'message_id' => 'required|exists:chats,id',
        ]);

        $read = ChatReadStatus::updateOrCreate(
            [
                'message_id' => $request->message_id,
                'receiver_id' => Auth::id(),
            ],
            [
                'is_read' => true,
            ]
        );

        return response()->json(['message' => 'Message marked as read.', 'read_status' => $read]);
    }

    public function bulkMarkRead(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $authId = Auth::id();

        // Get all messages sent by the other user to me
        $messages = Chat::where('sender_id', $request->user_id)
            ->where('receiver_id', $authId)
            ->pluck('id');

        if ($messages->isEmpty()) {
            return response()->json(['message' => 'No messages to update.']);
        }

        foreach ($messages as $messageId) {
            // Check if already marked as read
            $existing = ChatReadStatus::where('message_id', $messageId)
                ->where('receiver_id', $authId)
                ->first();

            if ($existing) {
                if (!$existing->is_read) {
                    $existing->is_read = true;
                    $existing->save();
                }
            } else {
                ChatReadStatus::create([
                    'message_id' => $messageId,
                    'receiver_id' => $authId,
                    'is_read' => true,
                ]);
            }
        }

        return response()->json(['message' => 'All messages marked as read.']);
    }


    // Fetch conversation between authenticated user & other user
    public function getConversation(Request $request, $userId)
    {
        $authId = Auth::id();

        $query = Chat::where(function ($q) use ($authId, $userId) {
                $q->where('sender_id', $authId)->where('receiver_id', $userId);
            })
            ->orWhere(function ($q) use ($authId, $userId) {
                $q->where('sender_id', $userId)->where('receiver_id', $authId);
            })
            ->with(['sender', 'receiver', 'attachments', 'reactions', 'readStatuses', 'parent', 'parent.sender'])
            ->orderBy('created_at', 'desc'); // Order latest first

        // paginate: default 10 per page
        $chats = $query->paginate(10);

        // Reverse inside page
        $chats->getCollection()->transform(function ($chat) {
            return $chat;
        });

        $chats->setCollection($chats->getCollection()->reverse()->values());

        return response()->json($chats);
    }

    public function unreadCount()
    {
        $authId = Auth::id();

        // Find all chat messages where this user is the receiver
        $query = Chat::where('receiver_id', $authId)
            ->whereDoesntHave('readStatuses', function($q) use ($authId) {
                $q->where('receiver_id', $authId)->where('is_read', true);
            });

        $count = $query->count();

        return response()->json([
            'unread_count' => $count
        ]);
    }

    public function getChatUsersList(Request $request)
    {
        $authId = Auth::id();
        $authUser = Auth::user();
        $role = $authUser->user_role; // 2=admin, 3=employee, 4=customer

        $userQuery = \App\Models\User::query()->where('id', '!=', $authId); // exclude self

        // Apply filters based on role
        if ($role == 2) {
            // Admin - can filter between employees and customers
            if ($request->has('type') && in_array($request->type, ['employee', 'customer'])) {
                $filterRole = ($request->type == 'employee') ? 3 : 4;
                $userQuery->where('user_role', $filterRole);
            }
        } elseif ($role == 3) {
            // Employee: only Admin & other Employees
            $userQuery->whereIn('user_role', [2, 3]);
        } elseif ($role == 4) {
            // Customer: only Admin
            $userQuery->where('user_role', 2);
        }

        // Preload unread count and last message via subqueries
        $users = $userQuery->get()->map(function ($user) use ($authId) {

            // Unread count for each user
            $unreadCount = Chat::where('sender_id', $user->id)
                ->where('receiver_id', $authId)
                ->whereDoesntHave('readStatuses', function ($q) use ($authId) {
                    $q->where('receiver_id', $authId)->where('is_read', true);
                })->count();

            // Get last message (from either direction)
            $lastMessage = Chat::where(function ($q) use ($authId, $user) {
                    $q->where('sender_id', $authId)->where('receiver_id', $user->id);
                })->orWhere(function ($q) use ($authId, $user) {
                    $q->where('sender_id', $user->id)->where('receiver_id', $authId);
                })->orderBy('created_at', 'desc')->first();

            return [
                'id' => $user->id,
                'name' => $user->name,
                'profile_pic' => $user->profile_pic,
                'user_role' => $user->user_role,
                'unread_count' => $unreadCount,
                'last_message' => $lastMessage ? \Str::limit($lastMessage->message, 30, '...') : null,
                'last_message_at' => $lastMessage ? $lastMessage->created_at : $user->created_at,
            ];
        });

        // Now sort by last message datetime (latest first)
        $users = $users->sortByDesc('last_message_at')->values();

        return response()->json([
            'users' => $users
        ]);
    }

    public function deleteMessage($id)
    {
        $chat = Chat::findOrFail($id);
        $authUser = Auth::user();

        // Permission check
        if ($chat->sender_id != $authUser->id && $authUser->user_role != 2) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $chat->delete();

        return response()->json(['message' => 'Message deleted successfully.']);
    }




}
