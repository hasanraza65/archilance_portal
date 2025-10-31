<?php

namespace App\Http\Controllers\API\employee;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Validation\Rule;
use Auth;

class UserManagementController extends Controller
{
    // Helper to detect role from route
    private function getRoleFromRequest(Request $request)
    {
        $path = $request->path(); // e.g., 'api/employee/employee-user/5'
        $parts = explode('/', $path); // ['api', 'employee', 'employee-user', '5']

        // Ensure the path is long enough
        if (count($parts) < 3) {
            abort(404, 'Invalid URL format');
        }

        // The role is typically in the second segment after 'api'
        $roleKey = $parts[1]; // 'admin', 'employee', etc.
        $segment = $parts[2]; // 'employee-user', etc.

        return match ($segment) {
            'employee-user' => 3,
            'admin-user' => 2,
            'customer-user' => 4,
            default => abort(404, 'Invalid user type'),
        };
    }


    public function index(Request $request)
    {
        $roleId = $this->getRoleFromRequest($request);
        $user = Auth::user();

        $users = User::where(function ($query) use ($roleId) {
            $query->where('user_role', $roleId)
                ->where('employee_type', '!=', 'Manager');
        })
            ->orWhere('id', $user->id) // ✅ include the logged-in user
            ->get();

        return response()->json($users);
    }

    public function store(Request $request)
    {

        $user = Auth::user();

        if ($user->employee_type != "Manager" && $user->employee_type != "Supervisor" && $user->employee_type != "Executive") {

            return response()->json(['message' => 'Unauthorized'], 403);

        }

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

        $user = Auth::user();

        if ($user->employee_type != "Manager" && $user->employee_type != "Supervisor" && $user->employee_type != "Executive") {

            return response()->json(['message' => 'Unauthorized'], 403);

        }

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

        $user = Auth::user();

        if ($user->employee_type != "Manager" && $user->employee_type != "Supervisor" && $user->employee_type != "Executive") {

            return response()->json(['message' => 'Unauthorized'], 403);

        }

        $roleId = $this->getRoleFromRequest($request);
        $user = User::where('user_role', $roleId)->findOrFail($id);
        $user->delete();

        return response()->json(['message' => 'User deleted successfully']);
    }
}

