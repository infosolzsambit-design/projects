<?php

use App\Http\Middleware\ApiKeyAuth;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\SanitizeInput;
use App\Http\Middleware\SecurityHeaders;
use App\Traits\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Spatie\Permission\Exceptions\UnauthorizedException as PermissionUnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(RequestId::class);
        $middleware->append(SecurityHeaders::class);

        // This is an API-only app with no "login" web route — without this,
        // Laravel's framework-registered default tries to redirect guests to
        // route('login') and throws RouteNotFoundException instead of
        // letting AuthenticationException reach our JSON exception handler.
        $middleware->redirectGuestsTo(fn () => null);

        // Every /api/v1 request must present a valid API key before anything
        // else (Sanctum auth, permission checks) is evaluated. SanitizeInput
        // runs after that (no point cleaning a request we're about to
        // reject) but before routing/validation ever sees the body — see
        // its docblock for why this is global instead of per-field.
        $middleware->api(prepend: [
            ApiKeyAuth::class,
            SanitizeInput::class,
        ]);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        $middleware->throttleApi();

        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $responder = new class
        {
            use ApiResponse;
        };

        // This application is API-only — every exception is rendered as JSON,
        // never as a Blade error page or stack trace.
        $exceptions->render(function (ValidationException $e, Request $request) use ($responder) {
            return $responder->validationError($e->errors());
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($responder) {
            return $responder->unauthorized('Unauthenticated.');
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) use ($responder) {
            return $responder->forbidden($e->getMessage() ?: 'This action is unauthorized.');
        });

        $exceptions->render(function (PermissionUnauthorizedException $e, Request $request) use ($responder) {
            return $responder->forbidden('This action is unauthorized.');
        });

        $exceptions->render(function (RoleDoesNotExist|PermissionDoesNotExist $e, Request $request) use ($responder) {
            return $responder->error($e->getMessage(), 422);
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) use ($responder) {
            return $responder->notFound('Resource not found.');
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($responder) {
            return $responder->notFound('The requested endpoint does not exist.');
        });

        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) use ($responder) {
            return $responder->error('This HTTP method is not allowed for this endpoint.', 405);
        });

        $exceptions->render(function (QueryException $e, Request $request) use ($responder) {
            Log::error('Database query exception.', [
                'request_id' => $request->attributes->get('request_id'),
                'message' => $e->getMessage(),
            ]);

            return $responder->error('A database error occurred. Please try again later.', 500);
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($responder) {
            return $responder->error($e->getMessage() ?: 'Request failed.', $e->getStatusCode());
        });

        $exceptions->render(function (Throwable $e, Request $request) use ($responder) {
            Log::error('Unhandled exception.', [
                'request_id' => $request->attributes->get('request_id'),
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            $message = config('app.debug')
                ? $e->getMessage()
                : 'An unexpected error occurred. Please try again later.';

            return $responder->error($message, 500);
        });
    })->create();
