<?php

/*
|--------------------------------------------------------------------------
| Platform Configuration
|--------------------------------------------------------------------------
|
| Shared technical settings used by app/Platform (HTTP runtime, shutdown,
| logging and observability). Values are read from the environment ONLY in
| this file so that `php artisan config:cache` is safe. Every value is
| validated at boot by App\Platform\Config\PlatformConfigValidator; invalid
| configuration aborts HTTP boot, artisan commands and the queue worker.
|
*/

return [

    /*
    | Logical service name attached to every log record and metric.
    */
    'service_name' => env('SERVICE_NAME', 'fixme-premium-backend'),

    /*
    | Canonical application environment: development | test | staging | production.
    | Laravel's own environment (config('app.env')) is derived from it: see config/app.php.
    */
    'environment' => env('APP_ENV', 'production'),

    /*
    | Minimum level written by the default "json" log channel (PSR-3 level name).
    */
    'log_level' => env('LOG_LEVEL', 'info'),

    'http' => [
        // host:port the API listens on (used by `php artisan platform:serve`).
        'addr' => env('HTTP_ADDR', '127.0.0.1:8000'),

        // Maximum PHP execution time per request, in seconds (enforced via set_time_limit()).
        // Keep the web server's upstream timeout (e.g. nginx fastcgi_read_timeout) slightly above it.
        'request_timeout' => env('HTTP_REQUEST_TIMEOUT', 30),

        // Web-server layer timeouts, in seconds. PHP cannot enforce these itself; they are
        // rendered into deployments/nginx/default.conf.template (client_header_timeout,
        // keepalive_timeout) and validated here so every layer reads the same values.
        'read_header_timeout' => env('HTTP_READ_HEADER_TIMEOUT', 10),
        'idle_timeout' => env('HTTP_IDLE_TIMEOUT', 60),
    ],

    /*
    | Graceful shutdown deadline, in seconds. Used as the queue worker's per-job
    | `--timeout` (the longest the worker waits for the in-flight job after SIGTERM)
    | and as the PHP-FPM `process_control_timeout` in deployments/.
    */
    'shutdown_timeout' => env('SHUTDOWN_TIMEOUT', 30),

    'metrics' => [
        'enabled' => env('METRICS_ENABLED', true),

        // Internal listener (host:port) serving GET /metrics. Requests reaching /metrics on any
        // other port receive a 404, so the endpoint is never exposed on the public API listener.
        'addr' => env('METRICS_ADDR', '127.0.0.1:9100'),

        // Laravel cache store holding metric samples between requests (PHP shares no memory
        // between requests). "file" works locally; use "redis" in staging/production.
        'store' => env('METRICS_STORE', 'file'),

        // Histogram buckets (seconds) for http_request_duration_seconds.
        'duration_buckets' => [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10],
    ],

    'request_id' => [
        'header' => 'X-Request-ID',
    ],

];
