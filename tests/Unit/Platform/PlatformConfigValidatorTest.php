<?php

use App\Platform\Config\InvalidConfigurationException;
use App\Platform\Config\PlatformConfigValidator;
use Illuminate\Config\Repository;

function platformConfig(array $overrides = []): Repository
{
    $config = new Repository([
        'app' => ['key' => null],
        'cache' => ['stores' => ['array' => [], 'file' => [], 'redis' => []]],
    ]);

    // Defaults documented in config/platform.php (APP_ENV aside), independent of the test environment.
    $config->set([
        'platform.environment' => 'development',
        'platform.service_name' => 'fixme-premium-backend',
        'platform.log_level' => 'info',
        'platform.http.addr' => '127.0.0.1:8000',
        'platform.http.request_timeout' => 30,
        'platform.http.read_header_timeout' => 10,
        'platform.http.idle_timeout' => 60,
        'platform.shutdown_timeout' => 30,
        'platform.queue.job_timeout' => 25,
        'platform.metrics.enabled' => true,
        'platform.metrics.addr' => '127.0.0.1:9100',
        'platform.metrics.store' => 'file',
        'platform.metrics.instance' => null,
    ]);

    $config->set($overrides);

    return $config;
}

function configErrors(array $overrides = []): array
{
    return app(PlatformConfigValidator::class)->errors(platformConfig($overrides));
}

it('accepts the documented defaults', function () {
    expect(configErrors())->toBe([]);
});

it('ships defaults in config/platform.php for every non-secret value', function () {
    $defaults = (function () {
        $keys = ['APP_ENV', 'SERVICE_NAME', 'LOG_LEVEL', 'HTTP_ADDR', 'HTTP_REQUEST_TIMEOUT',
            'HTTP_READ_HEADER_TIMEOUT', 'HTTP_IDLE_TIMEOUT', 'SHUTDOWN_TIMEOUT', 'QUEUE_JOB_TIMEOUT',
            'METRICS_ENABLED', 'METRICS_ADDR', 'METRICS_STORE', 'METRICS_INSTANCE'];
        $saved = [];

        foreach ($keys as $key) {
            $saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }

        try {
            return require base_path('config/platform.php');
        } finally {
            foreach ($saved as $key => [$env, $server, $getenv]) {
                if ($env !== null) {
                    $_ENV[$key] = $env;
                }
                if ($server !== null) {
                    $_SERVER[$key] = $server;
                }
                if ($getenv !== false) {
                    putenv("{$key}={$getenv}");
                }
            }
        }
    })();

    expect($defaults)
        ->environment->toBe('production')
        ->log_level->toBe('info')
        ->shutdown_timeout->toBe(30)
        ->and($defaults['queue'])->toBe(['job_timeout' => 25])
        ->and($defaults['http'])->toMatchArray([
            'addr' => '127.0.0.1:8000',
            'request_timeout' => 30,
            'read_header_timeout' => 10,
            'idle_timeout' => 60,
        ])
        ->and($defaults['metrics'])->toMatchArray([
            'enabled' => true,
            'addr' => '127.0.0.1:9100',
            'store' => 'file',
            'instance' => null,
        ]);
});

it('accepts valid custom values', function () {
    expect(configErrors([
        'platform.environment' => 'staging',
        'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        'platform.log_level' => 'warning',
        'platform.http.addr' => '0.0.0.0:8080',
        'platform.http.request_timeout' => '60',
        'platform.http.read_header_timeout' => 5,
        'platform.http.idle_timeout' => 120,
        'platform.shutdown_timeout' => 45,
        'platform.queue.job_timeout' => '40',
        'platform.metrics.addr' => '[::1]:9090',
        'platform.metrics.store' => 'redis',
        'platform.metrics.instance' => 'api-7f9c-pod.1',
    ]))->toBe([]);
});

it('accepts only the canonical environments', function (string $environment, bool $valid) {
    $errors = configErrors(['platform.environment' => $environment, 'app.key' => 'key']);

    expect($errors === [])->toBe($valid);
})->with([
    ['development', true],
    ['test', true],
    ['staging', true],
    ['production', true],
    ['local', false],
    ['testing', false],
    ['prod', false],
]);

it('rejects invalid values with actionable messages', function (string $key, mixed $value, string $message) {
    expect(configErrors([$key => $value]))->toContain($message);
})->with([
    'timeout too low' => ['platform.http.request_timeout', 0, 'HTTP_REQUEST_TIMEOUT must be an integer number of seconds between 1 and 300.'],
    'timeout too high' => ['platform.http.request_timeout', 301, 'HTTP_REQUEST_TIMEOUT must be an integer number of seconds between 1 and 300.'],
    'timeout not an integer' => ['platform.http.read_header_timeout', 'ten', 'HTTP_READ_HEADER_TIMEOUT must be an integer number of seconds.'],
    'idle timeout' => ['platform.http.idle_timeout', 901, 'HTTP_IDLE_TIMEOUT must be an integer number of seconds between 1 and 900.'],
    'shutdown timeout' => ['platform.shutdown_timeout', -1, 'SHUTDOWN_TIMEOUT must be an integer number of seconds between 1 and 300.'],
    'http address' => ['platform.http.addr', 'localhost', 'HTTP_ADDR must be a "host:port" address, e.g. 127.0.0.1:8000.'],
    'port range' => ['platform.http.addr', '127.0.0.1:70000', 'HTTP_ADDR port must be between 1 and 65535.'],
    'metrics address' => ['platform.metrics.addr', ':9100', 'METRICS_ADDR must be a "host:port" address, e.g. 127.0.0.1:9100.'],
    'same port' => ['platform.metrics.addr', '0.0.0.0:8000', 'METRICS_ADDR must use a different port than HTTP_ADDR so metrics stay off the public listener.'],
    'log level' => ['platform.log_level', 'verbose', 'LOG_LEVEL must be one of: debug, info, notice, warning, error, critical, alert, emergency.'],
    'job timeout' => ['platform.queue.job_timeout', 0, 'QUEUE_JOB_TIMEOUT must be an integer number of seconds between 1 and 299.'],
    'shutdown not above job timeout' => ['platform.shutdown_timeout', 25, 'SHUTDOWN_TIMEOUT must be greater than QUEUE_JOB_TIMEOUT so the worker can finish its in-flight job before it is killed.'],
    'metrics instance' => ['platform.metrics.instance', 'pod/1', 'METRICS_INSTANCE may only contain letters, digits, ".", "_" and "-".'],
    'metrics store' => ['platform.metrics.store', 'memcached', 'METRICS_STORE must name a cache store configured in config/cache.php (array, file, redis).'],
]);

it('reports missing required values', function () {
    expect(configErrors(['platform.http.addr' => null, 'platform.shutdown_timeout' => '']))
        ->toContain('HTTP_ADDR is required.')
        ->toContain('SHUTDOWN_TIMEOUT is required.');
});

it('requires APP_KEY in staging and production only', function () {
    expect(configErrors(['platform.environment' => 'production']))
        ->toContain('APP_KEY is required in staging and production (generate one with `php artisan key:generate --show`).')
        ->and(configErrors(['platform.environment' => 'development']))->toBe([]);
});

it('reports every invalid field together', function () {
    $config = platformConfig([
        'platform.environment' => 'local',
        'platform.http.request_timeout' => 0,
        'platform.metrics.addr' => 'nope',
    ]);

    try {
        app(PlatformConfigValidator::class)->validate($config);
        $this->fail('Expected configuration to be rejected.');
    } catch (InvalidConfigurationException $e) {
        expect($e->errors)->toHaveCount(3)
            ->and($e->getMessage())
            ->toContain('APP_ENV must be one of')
            ->toContain('HTTP_REQUEST_TIMEOUT')
            ->toContain('METRICS_ADDR');
    }
});

it('never echoes configuration values in error messages', function () {
    $secret = 'super-secret-value-123';

    $errors = configErrors([
        'platform.environment' => $secret,
        'platform.http.addr' => $secret,
        'platform.log_level' => $secret,
    ]);

    expect($errors)->not->toBeEmpty()
        ->and(implode("\n", $errors))->not->toContain($secret);
});
