<?php

use App\Platform\Observability\Metrics\CacheMetricsStore;
use App\Platform\Observability\Metrics\HttpMetrics;
use App\Platform\Observability\Metrics\PrometheusRenderer;
use App\Platform\Observability\Metrics\RuntimeMetrics;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Tests\Support\CountingArrayStore;

function metricsRenderer(): array
{
    $store = new CacheMetricsStore(new Repository(new ArrayStore));
    $http = new HttpMetrics($store, [0.1, 0.5, 1]);

    return [$http, new PrometheusRenderer($store, new RuntimeMetrics)];
}

it('counts requests per method, route template and status', function () {
    [$http, $renderer] = metricsRenderer();

    $http->record('GET', '/api/v1/users/{user}', 200, 0.05);
    $http->record('get', '/api/v1/users/{user}', 200, 0.2);
    $http->record('GET', '/api/v1/users/{user}', 404, 0.01);

    expect($renderer->render())
        ->toContain('# TYPE http_requests_total counter')
        ->toContain('http_requests_total{method="GET",route="/api/v1/users/{user}",status="200"} 2')
        ->toContain('http_requests_total{method="GET",route="/api/v1/users/{user}",status="404"} 1');
});

it('renders cumulative duration histograms', function () {
    [$http, $renderer] = metricsRenderer();

    $http->record('GET', '/health/live', 200, 0.05);
    $http->record('GET', '/health/live', 200, 0.3);
    $http->record('GET', '/health/live', 200, 2.0);

    expect($renderer->render())
        ->toContain('# TYPE http_request_duration_seconds histogram')
        ->toContain('http_request_duration_seconds_bucket{method="GET",route="/health/live",le="0.1"} 1')
        ->toContain('http_request_duration_seconds_bucket{method="GET",route="/health/live",le="0.5"} 2')
        ->toContain('http_request_duration_seconds_bucket{method="GET",route="/health/live",le="1"} 2')
        ->toContain('http_request_duration_seconds_bucket{method="GET",route="/health/live",le="+Inf"} 3')
        ->toContain('http_request_duration_seconds_sum{method="GET",route="/health/live"} 2.35')
        ->toContain('http_request_duration_seconds_count{method="GET",route="/health/live"} 3');
});

it('collapses non-standard methods into a bounded label', function () {
    [$http, $renderer] = metricsRenderer();

    $http->record('PROPFIND', 'unmatched', 405, 0.01);

    expect($renderer->render())
        ->toContain('http_requests_total{method="OTHER",route="unmatched",status="405"} 1')
        ->not->toContain('PROPFIND');
});

it('escapes label values', function () {
    [$http, $renderer] = metricsRenderer();

    $http->record('GET', "/a\"b\\c\nd", 200, 0.01);

    expect($renderer->render())->toContain('route="/a\\"b\\\\c\\nd"');
});

it('exposes PHP runtime gauges', function () {
    [, $renderer] = metricsRenderer();

    expect($renderer->render())
        ->toContain('# TYPE php_memory_usage_bytes gauge')
        ->toContain('php_memory_peak_usage_bytes ')
        ->toContain('php_info{version="'.PHP_VERSION.'",sapi="'.PHP_SAPI.'"} 1')
        ->toContain('php_opcache_enabled ');
});

it('persists samples in the configured cache store across requests', function () {
    $cache = new Repository(new ArrayStore);

    (new HttpMetrics(new CacheMetricsStore($cache, 'pod-a'), [1]))->record('GET', '/x', 200, 0.1);
    (new HttpMetrics(new CacheMetricsStore($cache, 'pod-a'), [1]))->record('GET', '/x', 200, 0.1);

    $snapshot = (new CacheMetricsStore($cache, 'pod-a'))->snapshot();

    expect($snapshot['counters']['http_requests_total'][0]['value'])->toBe(2)
        ->and($snapshot['histograms']['http_request_duration_seconds'][0])
        ->count->toBe(2)
        ->sum->toEqual(0.2)
        ->buckets->toBe(['1' => 2]);
});

it('keeps instances sharing one store separate so per-instance scrapes never double count', function () {
    $cache = new Repository(new ArrayStore);

    (new HttpMetrics(new CacheMetricsStore($cache, 'pod-a'), [1]))->record('GET', '/x', 200, 0.1);
    (new HttpMetrics(new CacheMetricsStore($cache, 'pod-b'), [1]))->record('GET', '/x', 200, 0.1);
    (new HttpMetrics(new CacheMetricsStore($cache, 'pod-b'), [1]))->record('GET', '/x', 200, 0.1);

    expect((new CacheMetricsStore($cache, 'pod-a'))->snapshot()['counters']['http_requests_total'][0]['value'])->toBe(1)
        ->and((new CacheMetricsStore($cache, 'pod-b'))->snapshot()['counters']['http_requests_total'][0]['value'])->toBe(2);
});

it('updates each value with an atomic increment instead of rewriting a shared blob', function () {
    $backend = new CountingArrayStore;
    $store = new CacheMetricsStore(new Repository($backend), 'pod-a');

    $store->incrementCounter('c', ['a' => '1']);
    $store->incrementCounter('c', ['a' => '1']);
    $store->incrementCounter('c', ['a' => '1']);

    // Only the first observation of a series writes the index; later ones only increment.
    expect($backend->writes)->toBe(1)
        ->and($backend->increments)->toBe(3);
});

it('still records the sample when the index lock is busy, and retries the registration', function () {
    $cache = new Repository(new ArrayStore);
    $lock = $cache->getStore()->lock('platform:metrics:v2:pod-a:index:lock', 10);
    $lock->get();

    $store = new CacheMetricsStore($cache, 'pod-a');
    $store->incrementCounter('c', []);

    expect($store->snapshot()['counters'])->toBe([]);

    $lock->release();
    $store->incrementCounter('c', []);

    expect($store->snapshot()['counters']['c'][0]['value'])->toBe(2);
});

it('flushes all samples of the instance', function () {
    $store = new CacheMetricsStore(new Repository(new ArrayStore), 'pod-a');
    $http = new HttpMetrics($store, [1]);

    $http->record('GET', '/x', 200, 0.1);
    $store->flush();

    expect($store->snapshot())->toBe(['counters' => [], 'histograms' => []]);

    $http->record('GET', '/x', 200, 0.1);

    expect($store->snapshot()['counters']['http_requests_total'][0]['value'])->toBe(1);
});
