<?php

declare(strict_types=1);

namespace App\Platform\Http\Middleware;

use App\Platform\Http\RequestTiming;
use App\Platform\Observability\Metrics\HttpMetrics;
use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Records request count and duration after the response is sent.
 *
 * Registered as global middleware so unmatched routes (404/405) and
 * exceptions rendered by the handler (5xx) are counted too. Scrapes of the
 * metrics endpoint itself are not recorded.
 */
final class RecordHttpMetrics
{
    public function __construct(
        private readonly HttpMetrics $metrics,
        private readonly Config $config,
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
        if (! (bool) $this->config->get('platform.metrics.enabled', true)) {
            return;
        }

        $route = RequestTiming::routeLabel($request);

        if ($route === '/metrics') {
            return;
        }

        try {
            $this->metrics->record(
                $request->getMethod(),
                $route,
                $response->getStatusCode(),
                RequestTiming::elapsedSeconds($request),
            );
        } catch (Throwable $e) {
            // Metrics must never break request handling.
            $this->logger->warning('metrics.record_failed', ['exception' => $e]);
        }
    }
}
