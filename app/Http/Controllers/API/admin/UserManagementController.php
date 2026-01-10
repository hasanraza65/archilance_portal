<?php

namespace App\Http\Controllers\api\admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Validation\Rule;
use App\Models\WorkSession;


class UserManagementController extends Controller
{
    // Helper to detect role from route
    private function getRoleFromRequest(Request $request)
    {
        $path = $request->path(); // e.g., 'api/admin/employee-user/5'
        $parts = explode('/', $path); // ['api', 'admin', 'employee-user', '5']

        // Find the index of 'admin'
        $adminIndex = array_search('admin', $parts);

        // Get the segment right after 'admin'
        $segment = $parts[$adminIndex + 1] ?? null;

        return match ($segment) {
            'employee-user' => 3,
            'admin-user' => 2,
            'customer-user' => 4,
            'supervisor-user' => 6,
            default => abort(404, 'Invalid user type'),
        };
    }


    public function index(Request $request)
    {
        $roleId = $this->getRoleFromRequest($request);

        $users = User::where('user_role', $roleId)
            ->when($roleId === 3, function ($query) {
                // Only for employee-user
                $query->with(['workSessions' => function ($q) {
                    $q->latest('id')->limit(1);
                }]);
            })
            ->get()
            ->map(function ($user) use ($roleId) {

                if ($roleId === 3) {
                    $session = $user->workSessions->first();

                    if ($session && is_null($session->end_date) && is_null($session->end_time)) {
                        $user->timer_status = 'Online';
                        $user->start_datetime = $session->start_date . ' ' . $session->start_time;
                    } else {
                        $user->timer_status = 'Offline';
                        $user->start_datetime = null;
                    }

                    unset($user->workSessions); // clean response
                }

                return $user;
            });

        return response()->json($users);
    }


    public function store(Request $request)
    {
        $roleId = $this->getRoleFromRequest($request);
        
        
        $existing = User::withTrashed()->where('email', $request->email)->first();

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
                $existing->update([
                    'name' => $request->name,
                    'username' => $request->username,
                    'phone' => $request->phone,
                    'password' => bcrypt($request->password),
                    'user_role' => $roleId,
                    'employee_type' => $request->employee_type ?? ''
                ]);
                return response()->json($existing, 200);
            } else {
                return response()->json(['error' => 'Email already exists.'], 422);
            }
        }


        $request->validate([
            'name' => 'required|string|max:255',
            'email' => [
                'nullable',
                'email',
                Rule::unique('users')->whereNull('deleted_at'),
            ],
            'username' => [
                'nullable',
                'string',
                Rule::unique('users')->whereNull('deleted_at'),
            ],
            'phone' => [
                'nullable',
                'string',
                Rule::unique('users')->whereNull('deleted_at'),
            ],
            'password' => 'required|string|min:6',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'username' => $request->username,
            'phone' => $request->phone,
            'password' => bcrypt($request->password),
            'user_role' => $roleId,
            'employee_type' => $request->employee_type ?? ''
        ]);

        return response()->json($user, 201);
    }

    public function show(Request $request, $id)
    {
        $roleId = $this->getRoleFromRequest($request);
        $user = User::where('user_role', $roleId)->findOrFail($id);
        return response()->json($user);
    }


    public function update(Request $request, $id)
    {
        $roleId = $this->getRoleFromRequest($request);
        $user = User::where('user_role', $roleId)->findOrFail($id);
    
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => [
                'nullable',
                'email',
                Rule::unique('users')->ignore($id)->whereNull('deleted_at'),
            ],
            'username' => [
                'nullable',
                'string',
                Rule::unique('users')->ignore($id)->whereNull('deleted_at'),
            ],
            'phone' => [
                'nullable',
                'string',
                Rule::unique('users')->ignore($id)->whereNull('deleted_at'),
            ],
            'password' => 'nullable|min:8|confirmed', // ✅ uses password_confirmation automatically
        ]);
    
        $updateData = [
            'name' => $request->name,
            'email' => $request->email,
            'username' => $request->username,
            'phone' => $request->phone,
            'employee_type' => $request->employee_type,
        ];
    
        // Update password only if provided
        if ($request->filled('password')) {
            $updateData['password'] = bcrypt($request->password);
        }
    
        $user->update($updateData);
    
        return response()->json($user);
    }


    
    public function destroy(Request $request, $id)
    {
        $roleId = $this->getRoleFromRequest($request);
        $user = User::where('user_role', $roleId)->findOrFail($id);
        $user->delete();

        return response()->json(['message' => 'User deleted successfully']);
    }
}
