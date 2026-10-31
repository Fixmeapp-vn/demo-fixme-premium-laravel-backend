<?php

declare(strict_types=1);

namespace App\Platform\Observability\Metrics;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Metric samples kept in a Laravel cache store.
 *
 * PHP-FPM / `artisan serve` share no memory between requests, so samples are
 * persisted in a cache store (METRICS_STORE). All series live under a single
 * key and are updated with read-modify-write under a cache lock when the store
 * supports locks (file, array, redis, database…). Use "redis" in production.
 *
 * @phpstan-import-type Snapshot from MetricsStore
 */
final class CacheMetricsStore implements MetricsStore
{
    private const KEY = 'platform:metrics:v1';

    private const LOCK_SECONDS = 5;

    private const LOCK_WAIT_SECONDS = 1;

    public function __construct(
        private readonly Cache $cache,
    ) {}

    public function incrementCounter(string $name, array $labels, float $by = 1.0): void
    {
        $this->mutate(function (array $data) use ($name, $labels, $by): array {
            $key = self::seriesKey($labels);

            $series = $data['counters'][$name][$key] ?? ['labels' => $labels, 'value' => 0.0];
            $series['value'] += $by;

            $data['counters'][$name][$key] = $series;

            return $data;
        });
    }

    public function observeHistogram(string $name, array $labels, float $value, array $buckets): void
    {
        $this->mutate(function (array $data) use ($name, $labels, $value, $buckets): array {
            $key = self::seriesKey($labels);

            $series = $data['histograms'][$name][$key] ?? [
                'labels' => $labels,
                'buckets' => array_fill_keys(array_map(self::formatBound(...), $buckets), 0),
                'sum' => 0.0,
                'count' => 0,
            ];

            foreach ($buckets as $bound) {
                if ($value <= $bound) {
                    $bucket = self::formatBound($bound);
                    $series['buckets'][$bucket] = ($series['buckets'][$bucket] ?? 0) + 1;
                }
            }

            $series['sum'] += $value;
            $series['count']++;

            $data['histograms'][$name][$key] = $series;

            return $data;
        });
    }

    public function snapshot(): array
    {
        return $this->read();
    }

    public function flush(): void
    {
        $this->cache->forget(self::KEY);
    }

    /**
     * @param  callable(Snapshot): Snapshot  $callback
     */
    private function mutate(callable $callback): void
    {
        $write = function () use ($callback): void {
            $this->cache->forever(self::KEY, $callback($this->read()));
        };

        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            $write();

            return;
        }

        try {
            $store->lock(self::KEY.':lock', self::LOCK_SECONDS)->block(self::LOCK_WAIT_SECONDS, $write);
        } catch (LockTimeoutException) {
            // Dropping one sample is preferable to delaying the response.
        }
    }

    /**
     * @return Snapshot
     */
    private function read(): array
    {
        $data = $this->cache->get(self::KEY);

        if (! is_array($data)) {
            return ['counters' => [], 'histograms' => []];
        }

        /** @var Snapshot $data */
        return $data + ['counters' => [], 'histograms' => []];
    }

    /**
     * @param  array<string, string>  $labels
     */
    private static function seriesKey(array $labels): string
    {
        ksort($labels);

        return (string) json_encode($labels);
    }

    public static function formatBound(float|int $bound): string
    {
        return rtrim(rtrim(sprintf('%.6F', $bound), '0'), '.');
    }
}
