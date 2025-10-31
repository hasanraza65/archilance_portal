<?php



use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Register global middleware (runs on every request)
        $middleware->append([
            App\Http\Middleware\HandleCors::class,
        ]);

        // Register named middleware for route usage (e.g., 'role')
        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'employeeType' => \App\Http\Middleware\EmployeeTypeMiddleware::class,
            'auth' => \Illuminate\Auth\Middleware\Authenticate::class,
            'auth:sanctum' => \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
