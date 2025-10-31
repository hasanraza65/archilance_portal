<?php


namespace App\Http\Middleware;

use Closure;

class HandleCors
{
    public function handle($request, Closure $next)
    {
        $origin = $request->headers->get('Origin');

        $allowedOrigins = [
            'http://localhost:5173', // React dev server
            'https://your-production-frontend.com' // Add any other allowed domains
        ];

        if (in_array($origin, $allowedOrigins)) {
            return $next($request)
                ->header('Access-Control-Allow-Origin', $origin)
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization')
                ->header('Access-Control-Allow-Credentials', 'true');
        }

        return $next($request);
    }
}
