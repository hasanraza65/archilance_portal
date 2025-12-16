<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Notification;
use Illuminate\Support\Facades\Validator;
use Auth;


class AuthController extends Controller
{

    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:users,email',
            'username' => 'nullable|string|unique:users,username',
            'phone' => 'nullable|string|unique:users,phone',
            'password' => 'required|string|min:6|confirmed', // expects password_confirmation field
            'profile_pic' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'username' => $request->username,
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'User registered successfully.',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
        ]);
    }

    public function login(Request $request)
    {
        $request->validate([
            'login' => 'required|string',
            'password' => 'required|string'
        ]);

        $user = User::where('email', $request->login)
            ->orWhere('username', $request->login)
            ->orWhere('phone', $request->login)
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        //\Log::info('fcm token '.$request->fcm_token);

        $user->fcm_token = $request->fcm_token;
        $user->update();

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user
        ]);
    }

    public function logout(Request $request)
    {
        // Delete current access token
        $request->user()->currentAccessToken()->delete();

        // Set fcm_token to null
        $request->user()->update([
            'fcm_token' => null
        ]);

        return response()->json(['message' => 'Logged out']);
    }

    public function me(Request $request)
    {
        return response()->json($request->user());
    }

    public function updateProfile(Request $request)
    {
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'email' => 'nullable|email|unique:users,email,' . $user->id,
            'username' => 'nullable|string|unique:users,username,' . $user->id,
            'phone' => 'nullable|string|unique:users,phone,' . $user->id,
            'profile_pic' => 'nullable|string',
            'password' => 'nullable|string|min:6|confirmed', // expects password_confirmation
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Update user fields
        $user->name = $request->name ?? $user->name;
        $user->email = $request->email ?? $user->email;
        $user->username = $request->username ?? $user->username;
        $user->phone = $request->phone ?? $user->phone;
        $user->profile_pic = $request->profile_pic ?? $user->profile_pic;

        if ($request->filled('password')) {
            $user->password = Hash::make($request->password);
        }

        $user->save();

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => $user,
        ]);
    }

    public function updateFCMToken(Request $request)
    {

        $user = User::findOrFail(Auth::user()->id);
        $user->fcm_token = $request->fcm_token;
        $user->update();

        \Log::info('update fcm worked: ' . $request->fcm_token);

        return response()->json([
            'message' => 'Token updated successfully.',
            'user' => $user,
        ]);
    }


    public function myNotifications(){

        $notifications = Notification::where('user_id',Auth::user()->id)->orderBy('id','desc')->paginate(10);

        $count_unread = Notification::where('user_id',Auth::user()->id)->where('is_read',0)->count('id');

        return response()->json([
            "notifications"=>$notifications,
            "total_unread" => $count_unread
        ]);

    }

    public function updateReadStatusNotifications(){

        Notification::where('user_id',Auth::user()->id)->update(["is_read"=>1]);

        return response()->json([
            'message' => 'Read status updated successfully.',
        ]);

    }
}
