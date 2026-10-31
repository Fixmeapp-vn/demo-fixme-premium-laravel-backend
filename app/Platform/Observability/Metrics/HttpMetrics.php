<?php

declare(strict_types=1);

namespace App\Platform\Observability\Metrics;

/**
 * HTTP request metrics with bounded label sets.
 *
 *   http_requests_total{method, route, status}
 *   http_request_duration_seconds{method, route}   (histogram)
 *
 * "route" is the route URI template, or "unmatched" for 404/405 requests that
 * matched no route; "method" is collapsed to OTHER for non-standard verbs.
 */
final class HttpMetrics
{
    public const REQUESTS_TOTAL = 'http_requests_total';

    public const REQUEST_DURATION = 'http_request_duration_seconds';

    public const HELP = [
        self::REQUESTS_TOTAL => 'Total number of HTTP requests handled.',
        self::REQUEST_DURATION => 'HTTP request duration in seconds.',
    ];

    private const METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    /**
     * @param  list<float|int>  $durationBuckets
     */
    public function __construct(
        private readonly MetricsStore $store,
        private readonly array $durationBuckets,
    ) {}

    public function record(string $method, string $route, int $status, float $durationSeconds): void
    {
        $method = strtoupper($method);
        $method = in_array($method, self::METHODS, true) ? $method : 'OTHER';

        $this->store->incrementCounter(self::REQUESTS_TOTAL, [
            'method' => $method,
            'route' => $route,
            'status' => (string) $status,
        ]);

        $this->store->observeHistogram(self::REQUEST_DURATION, [
            'method' => $method,
            'route' => $route,
        ], $durationSeconds, $this->durationBuckets);
    }

    /**
     * @return list<float|int>
     */
    public function durationBuckets(): array
    {
        return $this->durationBuckets;
    }
}
