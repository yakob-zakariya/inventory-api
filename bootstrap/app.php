<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

        // Requests from the web app (SANCTUM_STATEFUL_DOMAINS) get a session cookie and CSRF checks.
        // Token requests (mobile, Postman) are unaffected.
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $rendersJson = fn (Request $request): bool => $request->is('api/*') || $request->expectsJson();

        $exceptions->shouldRenderJsonWhen($rendersJson);

        // Hide model class names and ids that Laravel puts in 404 messages.
        $exceptions->render(fn (NotFoundHttpException $e, Request $request) => $rendersJson($request)
            ? response()->json(['message' => 'Resource not found.'], 404)
            : null);

        // An expired or missing CSRF token on a cookie-session request.
        // Laravel turns TokenMismatchException into a 419 HttpException before render callbacks run.
        $exceptions->render(fn (HttpException $e, Request $request) => $rendersJson($request) && $e->getStatusCode() === 419
            ? response()->json(['message' => 'CSRF token mismatch. Fetch /sanctum/csrf-cookie and try again.'], 419)
            : null);

        // Every 403 (permission middleware, Form Request authorize(), policies) has the same body.
        $exceptions->render(fn (UnauthorizedException $e, Request $request) => $rendersJson($request)
            ? response()->json(['message' => 'This action is unauthorized.'], 403)
            : null);
        $exceptions->render(fn (AccessDeniedHttpException $e, Request $request) => $rendersJson($request)
            ? response()->json(['message' => $e->getMessage() ?: 'This action is unauthorized.'], 403)
            : null);
    })->create();
