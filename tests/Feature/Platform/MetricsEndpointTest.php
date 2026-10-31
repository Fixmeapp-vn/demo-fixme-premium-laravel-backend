<?php

use App\Platform\Observability\Metrics\MetricsStore;
use Illuminate\Support\Facades\Route;

const METRICS_URL = 'http://127.0.0.1:9100/metrics';

beforeEach(function () {
    config(['platform.metrics.addr' => '127.0.0.1:9100']);
    app(MetricsStore::class)->flush();
    Route::get('/api/v1/_test/items/{item}', fn (string $item) => ['item' => $item]);
    Route::get('/api/v1/_test/boom', fn () => throw new RuntimeException('boom'));
});

it('serves Prometheus metrics on the internal listener', function () {
    $this->get(METRICS_URL)
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
});

it('is not reachable through the public listener', function () {
    $this->get('http://localhost/metrics')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json');

    $this->get('http://localhost:8000/metrics')->assertNotFound();
});

it('is disabled when METRICS_ENABLED is false', function () {
    config(['platform.metrics.enabled' => false]);

    $this->get(METRICS_URL)->assertNotFound();
});

it('counts requests by method, route template and status', function () {
    $this->get('/api/v1/_test/items/1')->assertOk();
    $this->get('/api/v1/_test/items/2')->assertOk();
    $this->get('/api/v1/_test/boom')->assertStatus(500);
    $this->post('/health/live')->assertStatus(405);

    $body = $this->get(METRICS_URL)->getContent();

    expect($body)
        ->toContain('http_requests_total{method="GET",route="/api/v1/_test/items/{item}",status="200"} 2')
        ->toContain('http_requests_total{method="GET",route="/api/v1/_test/boom",status="500"} 1')
        ->toContain('http_requests_total{method="POST",route="unmatched",status="405"} 1')
        ->toContain('http_request_duration_seconds_count{method="GET",route="/api/v1/_test/items/{item}"} 2');
});

it('does not create unbounded route labels for unknown paths', function () {
    foreach (range(1, 5) as $i) {
        $this->get("/random/path/{$i}")->assertNotFound();
    }

    $body = $this->get(METRICS_URL)->getContent();

    expect($body)
        ->toContain('http_requests_total{method="GET",route="unmatched",status="404"} 5')
        ->not->toContain('/random/path');
});

it('does not count scrapes of the metrics endpoint', function () {
    $this->get(METRICS_URL);
    $body = $this->get(METRICS_URL)->getContent();

    expect($body)->not->toContain('route="/metrics"');
});
