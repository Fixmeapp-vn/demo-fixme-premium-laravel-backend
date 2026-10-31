<?php

namespace Tests\Support;

use Symfony\Component\Process\Process;

/**
 * Runs artisan in a separate PHP process (real boot, real signals).
 */
final class Artisan
{
    /**
     * @param  list<string>  $arguments
     * @param  array<string, string|false>  $env
     */
    public static function process(array $arguments, array $env = [], float $timeout = 30): Process
    {
        $process = new Process(
            [PHP_BINARY, dirname(__DIR__, 2).'/artisan', ...$arguments],
            dirname(__DIR__, 2),
            $env + [
                'APP_ENV' => 'test',
                'LOG_CHANNEL' => 'json',
                'LOG_STREAM' => 'php://stderr',
                'LOG_LEVEL' => 'debug',
                'CACHE_STORE' => 'array',
                'METRICS_STORE' => 'array',
                'QUEUE_CONNECTION' => 'sync',
            ],
            null,
            $timeout,
        );

        return $process;
    }
}
