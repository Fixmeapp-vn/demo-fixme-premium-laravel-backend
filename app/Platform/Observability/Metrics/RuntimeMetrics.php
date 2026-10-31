<?php

declare(strict_types=1);

namespace App\Platform\Observability\Metrics;

/**
 * PHP runtime gauges, sampled at scrape time from the process serving the
 * scrape (under PHP-FPM this is one pool worker; values are per process).
 */
final class RuntimeMetrics
{
    /**
     * @return list<array{name: string, help: string, labels: array<string, string>, value: float|int}>
     */
    public function collect(): array
    {
        $gauges = [
            [
                'name' => 'php_info',
                'help' => 'PHP runtime information.',
                'labels' => ['version' => PHP_VERSION, 'sapi' => PHP_SAPI],
                'value' => 1,
            ],
            [
                'name' => 'php_memory_usage_bytes',
                'help' => 'Memory currently allocated by the PHP process.',
                'labels' => [],
                'value' => memory_get_usage(true),
            ],
            [
                'name' => 'php_memory_peak_usage_bytes',
                'help' => 'Peak memory allocated by the PHP process.',
                'labels' => [],
                'value' => memory_get_peak_usage(true),
            ],
        ];

        return [...$gauges, ...$this->opcache()];
    }

    /**
     * @return list<array{name: string, help: string, labels: array<string, string>, value: float|int}>
     */
    private function opcache(): array
    {
        $status = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
        $enabled = is_array($status) && ($status['opcache_enabled'] ?? false) === true;

        $gauges = [[
            'name' => 'php_opcache_enabled',
            'help' => 'Whether OPcache is enabled for this process (1) or not (0).',
            'labels' => [],
            'value' => $enabled ? 1 : 0,
        ]];

        if (! $enabled) {
            return $gauges;
        }

        /** @var array{memory_usage?: array{used_memory?: int, free_memory?: int}, opcache_statistics?: array{hits?: int, misses?: int, num_cached_scripts?: int}} $status */
        $memory = $status['memory_usage'] ?? [];
        $stats = $status['opcache_statistics'] ?? [];

        return [
            ...$gauges,
            ['name' => 'php_opcache_used_memory_bytes', 'help' => 'OPcache shared memory in use.', 'labels' => [], 'value' => $memory['used_memory'] ?? 0],
            ['name' => 'php_opcache_free_memory_bytes', 'help' => 'OPcache shared memory free.', 'labels' => [], 'value' => $memory['free_memory'] ?? 0],
            ['name' => 'php_opcache_cached_scripts', 'help' => 'Number of scripts cached by OPcache.', 'labels' => [], 'value' => $stats['num_cached_scripts'] ?? 0],
            ['name' => 'php_opcache_hits_total', 'help' => 'OPcache hits.', 'labels' => [], 'value' => $stats['hits'] ?? 0],
            ['name' => 'php_opcache_misses_total', 'help' => 'OPcache misses.', 'labels' => [], 'value' => $stats['misses'] ?? 0],
        ];
    }
}
