<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Auth;

class ProfileManagementController extends Controller
{
    public function updateProfile(Request $request)
    {
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
           // 'email' => 'nullable|email|unique:users,email,' . $user->id,
            'username' => 'nullable|string|unique:users,username,' . $user->id,
            'phone' => 'nullable|string|unique:users,phone,' . $user->id,
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Update user fields
        $user->name = $request->name ?? $user->name;
       // $user->email = $request->email ?? $user->email;
        $user->username = $request->username ?? $user->username;
        $user->phone = $request->phone ?? $user->phone;

        $user->save();

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => $user,
        ]);
    }

    public function updatePassword(Request $request)
    {
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'password' => 'required|string|min:6|confirmed', // expects password_confirmation field
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Check if current password matches
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['error' => 'Current password is incorrect.'], 400);
        }

        // Update password
        $user->password = Hash::make($request->password);
        $user->is_default_pass = 0;
        $user->save();

        return response()->json([
            'message' => 'Password updated successfully.',
        ]);
    }

    public function updateProfilePic(Request $request)
    {
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'profile_pic' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048', // image validation
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Store the image and get its path
        $profilePicPath = $request->file('profile_pic')->store('profile_pics', 'public');

        // Update user profile picture path in the database
        $user->profile_pic = $profilePicPath;
        $user->save();

        return response()->json([
            'message' => 'Profile picture updated successfully.',
            'profile_pic' => asset('storage/' . $profilePicPath),
        ]);
    }

}
