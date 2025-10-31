<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use App\Models\UserRole;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserRoleController extends Controller
{
    public function store(Request $request)
    {
        // Get the company_id from the authenticated user's company relationship
        $company_id = null;

        // Check if the user is an admin (user role 2), and get their associated company_id
        if (Auth::user()->user_role == 2) {
            // Assuming the User model has a company() relationship
            $company = Auth::user()->company; // Fetch the related company
            $company_id = $company ? $company->id : null; // If company exists, get its ID
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|unique:user_roles,slug',
            'is_system' => 'nullable|boolean',
        ]);

        // Add company_id if available
        $validated['company_id'] = $company_id;

        $role = UserRole::create($validated);

        return response()->json($role, 201);
    }

    public function show($id)
    {
        $role = UserRole::findOrFail($id);
        return response()->json($role);
    }

    public function update(Request $request, $id)
    {
        $role = UserRole::findOrFail($id);

        // Get the company_id from the authenticated user's company relationship
        $company_id = null;

        if (Auth::user()->user_role == 2) {
            $company = Auth::user()->company; // Fetch the related company
            $company_id = $company ? $company->id : null;
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|unique:user_roles,slug,' . $role->id,
            'is_system' => 'nullable|boolean',
        ]);

        // Add company_id if available
        $validated['company_id'] = $company_id;

        $role->update($validated);

        return response()->json($role);
    }

    public function destroy($id)
    {
        $role = UserRole::findOrFail($id);

        if ($role->is_system) {
            return response()->json(['message' => 'Cannot delete system role'], 400);
        }

        $role->delete();

        return response()->json(['message' => 'Role deleted successfully']);
    }
}
