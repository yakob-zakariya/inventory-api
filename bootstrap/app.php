<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
        ]);

        // API-only: there is no login page, so guests get a 401 instead of a redirect.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $rendersJson = fn (Request $request): bool => $request->is('api/*') || $request->expectsJson();

        $exceptions->shouldRenderJsonWhen($rendersJson);

        // Hide model class names and ids that Laravel puts in 404 messages.
        $exceptions->render(fn (NotFoundHttpException $e, Request $request) => $rendersJson($request)
            ? response()->json(['message' => 'Resource not found.'], 404)
            : null);

        // Use the same 403 message as Laravel's own authorization failures.
        $exceptions->render(fn (UnauthorizedException $e, Request $request) => $rendersJson($request)
            ? response()->json(['message' => 'This action is unauthorized.'], 403)
            : null);
    })->create();
