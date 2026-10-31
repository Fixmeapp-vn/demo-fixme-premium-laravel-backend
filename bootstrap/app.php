<?php

use App\Platform\Http\Middleware\AssignRequestId;
use App\Platform\Http\Middleware\EnforceRequestTimeout;
use App\Platform\Http\Middleware\LogAccess;
use App\Platform\Http\Middleware\RecordHttpMetrics;
use App\Platform\Http\ProblemDetails\ProblemDetailsRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        then: function (): void {
            require __DIR__.'/../routes/platform.php';
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global middleware order (outermost first):
        //   1. AssignRequestId    – must run first so every later log line, exception
        //                           report and response carries the request ID.
        //   2. RecordHttpMetrics  – wraps everything below, so 404/405 and handled
        //                           exceptions (rendered to 4xx/5xx) are counted.
        //   3. LogAccess          – access log written on terminate, after the response.
        //   4. Laravel defaults   – TrustProxies, HandleCors, ValidatePostSize, …
        //   5. EnforceRequestTimeout – applies HTTP_REQUEST_TIMEOUT before routing.
        // Exceptions thrown anywhere inside are converted to Problem Details by the
        // exception handler before they reach 1–3, so those always see a response.
        $middleware->prepend([
            AssignRequestId::class,
            RecordHttpMetrics::class,
            LogAccess::class,
        ]);

        $middleware->append(EnforceRequestTimeout::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // This application only serves JSON APIs: every error is an RFC 9457
        // Problem Details document. Stack traces are logged, never returned.
        $exceptions->shouldRenderJsonWhen(fn (): bool => true);

        $exceptions->render(
            fn (Throwable $e, Request $request) => app(ProblemDetailsRenderer::class)->render($e),
        );
    })->create();
