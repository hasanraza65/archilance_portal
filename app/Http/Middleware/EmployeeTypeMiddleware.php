<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EmployeeTypeMiddleware
{
    public function handle(Request $request, Closure $next, ...$types): Response
    {
        $user = $request->user();

        if (!$user || $user->user_role != 3) {
            return response()->json(['message' => 'Unauthorized - Not an Employee'], 403);
        }

        if (!$user->employee_type || !in_array($user->employee_type, $types)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return $next($request);
    }
}
