<?php

use App\Http\Middleware\FinishStreamedResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Outermost, so it sees the final response after every other middleware.
        $middleware->prepend(FinishStreamedResponse::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // One error shape for the API: {status, message[, errors]}. Web pages are untouched.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            [$status, $headers] = match (true) {
                $e instanceof ValidationException => [$e->status, []],
                $e instanceof AuthenticationException => [401, []],
                $e instanceof HttpExceptionInterface => [$e->getStatusCode(), $e->getHeaders()],
                default => [null, []],
            };

            if ($status === null) {
                return null;
            }

            $body = [
                'status' => 'error',
                'message' => $e->getMessage() !== '' ? $e->getMessage() : (Response::$statusTexts[$status] ?? 'Error'),
            ];

            if ($e instanceof ValidationException) {
                $body['errors'] = $e->errors();
            }

            return response()->json($body, $status, $headers);
        });
    })->create();
