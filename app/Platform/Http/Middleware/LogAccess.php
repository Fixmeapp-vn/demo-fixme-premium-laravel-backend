<?php

declare(strict_types=1);

namespace App\Platform\Http\Middleware;

use App\Platform\Http\RequestId;
use App\Platform\Http\RequestTiming;
use Closure;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Emits one structured "http.request" log per request once the response has
 * been sent (terminable middleware).
 *
 * Only the route URI template is logged (e.g. /api/v1/users/{user}), never the
 * raw path or query string, so URL parameters cannot leak into logs.
 * Probe endpoints (health, metrics) are logged at debug level.
 */
final class LogAccess
{
    /** Routes logged at debug level instead of info. */
    public const QUIET_ROUTES = ['/health/live', '/metrics'];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        RequestTiming::start($request);

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        $route = RequestTiming::routeLabel($request);
        $status = $response->getStatusCode();

        $context = [
            'method' => $request->getMethod(),
            'route' => $route,
            'status' => $status,
            'duration_ms' => round(RequestTiming::elapsedSeconds($request) * 1000, 3),
            'request_id' => RequestId::from($request),
        ];

        $level = match (true) {
            in_array($route, self::QUIET_ROUTES, true) => 'debug',
            $status >= 500 => 'error',
            $status >= 400 => 'warning',
            default => 'info',
        };

        $this->logger->log($level, 'http.request', $context);
    }
}
