<?php

namespace App\Http\Controllers\API\customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CustomerTeam;
use Auth;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Mail;

class CustomerTeamController extends Controller
{
    public function index()
    {

        $data = CustomerTeam::where('customer_id', Auth::user()->id)
            ->with(['customerUser', 'teamUser'])
            ->paginate(10);

        return response()->json($data);
    }


    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email',
            'phone' => 'nullable|string',
        ]);

        // Check if the user already exists
        $existingUser = User::withTrashed()->where('email', $request->email)->first();

        if ($existingUser) {
            // If user is soft-deleted, restore
            if ($existingUser->trashed()) {
                $existingUser->restore();
            }

            // Optionally update user info (optional)
            /*
            $existingUser->update([
                'name' => $request->name,
                'phone' => $request->phone,
            ]); */

            $userId = $existingUser->id;


            // Send email without password
            Mail::send('mails.team-invite', [
                'name' => $request->name,
                'email' => $request->email,
                'password' => null, // No password for existing user
                'customerName' => Auth::user()->name
            ], function ($message) use ($request) {
                $message->to($request->email)
                    ->subject('Invitation to Join Our Team');
            });


        } else {
            // Generate random password
            $randomPassword = Str::random(8);

            // Create new user
            $newUser = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'password' => Hash::make($randomPassword),
                'is_default_pass' => 1,
                'user_role' => 5,
            ]);

            $userId = $newUser->id;


            // Send email with password
            Mail::send('mails.team-invite', [
                'name' => $request->name,
                'email' => $request->email,
                'password' => $randomPassword, // Send password for new user
                'customerName' => Auth::user()->name
            ], function ($message) use ($request) {
                $message->to($request->email)
                    ->subject('Invitation to Join Our Team');
            });

            // Optional: send email or return the password to frontend
            // e.g., Mail::to($newUser->email)->send(new SendTeamInvite($randomPassword));
        }

        // Create team member record
        $team = CustomerTeam::create([
            'customer_id' => Auth::id(),
            'team_user_id' => $userId,
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'status' => 'Pending',
        ]);

        return response()->json([
            'message' => 'Team member added successfully.',
            'team_member' => $team
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email',
            'phone' => 'nullable|string',
        ]);

        $teamMember = CustomerTeam::where('customer_id', Auth::id())->findOrFail($id);

        $originalUser = User::find($teamMember->team_user_id);

        // If email is unchanged, just update name/phone/email on team + user
        if ($originalUser && $originalUser->email === $request->email) {
            $teamMember->update([
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
            ]);

            $originalUser->update([
                'name' => $request->name,
                'phone' => $request->phone,
            ]);
        } else {
            // Email changed, check if a new user with this email exists
            $existingUser = User::where('email', $request->email)->first();

            if (!$existingUser) {
                // Create new user
                $randomPassword = Str::random(8);

                $newUser = User::create([
                    'name' => $request->name,
                    'email' => $request->email,
                    'phone' => $request->phone,
                    'password' => Hash::make($randomPassword),
                    'is_default_pass' => 1,
                    'user_role' => 5,
                ]);

                $teamMember->update([
                    'team_user_id' => $newUser->id,
                ]);
            } else {
                // Assign existing user
                $teamMember->update([
                    'team_user_id' => $existingUser->id,
                ]);
            }

            // Update team table info regardless
            $teamMember->update([
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
            ]);
        }

        return response()->json([
            'message' => 'Team member updated successfully.',
            'team_member' => $teamMember
        ]);
    }


    public function show($id)
    {
        $teamMember = CustomerTeam::where('customer_id', Auth::id())
            ->with(['customerUser', 'teamUser'])
            ->findOrFail($id);

        return response()->json($teamMember);
    }


    public function destroy($id)
    {
        $teamMember = CustomerTeam::where('customer_id', Auth::id())->findOrFail($id);

        $teamMember->delete();

        return response()->json([
            'message' => 'Team member deleted successfully.'
        ]);
    }



}
