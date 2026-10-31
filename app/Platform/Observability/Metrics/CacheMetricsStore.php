<?php

declare(strict_types=1);

namespace App\Platform\Observability\Metrics;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Metric samples kept in a Laravel cache store, one atomic counter per value.
 *
 * PHP-FPM shares no memory between requests, so samples live in a cache
 * store (METRICS_STORE). The hot path never takes a global lock: every value
 * (counter, histogram bucket, count, sum) is its own key updated with the
 * store's atomic increment (Redis INCRBY, database row lock, …). Durations are
 * summed as integer microseconds so they can use integer increments too.
 *
 * Series are listed in a per-instance index, written only the first time a
 * series is seen (under a short lock). If that lock times out, the "seen"
 * marker is removed again so the next request retries the registration; the
 * sample itself is never dropped.
 *
 * All keys are namespaced by instance (METRICS_INSTANCE, default: hostname), so
 * several pods sharing one Redis each expose only their own samples and a
 * per-pod Prometheus scrape never double counts.
 *
 * The "file" store is not atomic across concurrent processes: use it only for
 * local development, and "redis" elsewhere.
 *
 * @phpstan-import-type Snapshot from MetricsStore
 *
 * @phpstan-type Descriptor array{type: 'counter'|'histogram', name: string, labels: array<string, string>, buckets?: list<string>}
 */
final class CacheMetricsStore implements MetricsStore
{
    private const VERSION = 'v2';

    private const LOCK_SECONDS = 5;

    private const LOCK_WAIT_SECONDS = 1;

    private const MICROS = 1_000_000;

    /** A TTL makes add() atomic (SET NX EX); an expired marker only re-registers the same series. */
    private const SEEN_TTL_SECONDS = 86_400;

    private readonly string $prefix;

    public function __construct(
        private readonly Cache $cache,
        string $instance = 'local',
    ) {
        $this->prefix = 'platform:metrics:'.self::VERSION.':'.$instance.':';
    }

    public function incrementCounter(string $name, array $labels, int $by = 1): void
    {
        $id = self::seriesId('counter', $name, $labels);

        $this->register($id, ['type' => 'counter', 'name' => $name, 'labels' => $labels]);
        $this->increment($id.':value', $by);
    }

    public function observeHistogram(string $name, array $labels, float $value, array $buckets): void
    {
        $id = self::seriesId('histogram', $name, $labels);
        $bounds = array_map(self::formatBound(...), $buckets);

        $this->register($id, ['type' => 'histogram', 'name' => $name, 'labels' => $labels, 'buckets' => $bounds]);

        // Only the smallest matching bucket is incremented; snapshot() makes them cumulative.
        foreach ($buckets as $i => $bound) {
            if ($value <= $bound) {
                $this->increment($id.':bucket:'.$bounds[$i], 1);
                break;
            }
        }

        $this->increment($id.':count', 1);
        $this->increment($id.':sum_us', (int) round(max(0.0, $value) * self::MICROS));
    }

    public function snapshot(): array
    {
        $snapshot = ['counters' => [], 'histograms' => []];

        foreach ($this->index() as $id => $series) {
            if ($series['type'] === 'counter') {
                $snapshot['counters'][$series['name']][] = [
                    'labels' => $series['labels'],
                    'value' => $this->int($id.':value'),
                ];

                continue;
            }

            $bounds = $series['buckets'] ?? [];
            $keys = array_map(fn (string $le): string => $this->prefix.$id.':bucket:'.$le, $bounds);
            $values = $keys === [] ? [] : $this->cache->many($keys);

            $cumulative = 0;
            $buckets = [];

            foreach ($bounds as $i => $le) {
                $cumulative += (int) ($values[$keys[$i]] ?? 0);
                $buckets[$le] = $cumulative;
            }

            $snapshot['histograms'][$series['name']][] = [
                'labels' => $series['labels'],
                'buckets' => $buckets,
                'sum' => $this->int($id.':sum_us') / self::MICROS,
                'count' => $this->int($id.':count'),
            ];
        }

        return $snapshot;
    }

    public function flush(): void
    {
        foreach (array_keys($this->index()) as $id) {
            foreach (['value', 'count', 'sum_us', 'seen'] as $suffix) {
                $this->cache->forget($this->prefix.$id.':'.$suffix);
            }

            foreach ($this->index()[$id]['buckets'] ?? [] as $le) {
                $this->cache->forget($this->prefix.$id.':bucket:'.$le);
            }
        }

        $this->cache->forget($this->prefix.'index');
    }

    public static function formatBound(float|int $bound): string
    {
        return rtrim(rtrim(sprintf('%.6F', $bound), '0'), '.');
    }

    /**
     * @param  Descriptor  $descriptor
     */
    private function register(string $id, array $descriptor): void
    {
        // Atomic "first time seen" check (SET NX on Redis); cheap on every later request.
        if (! $this->cache->add($this->prefix.$id.':seen', 1, self::SEEN_TTL_SECONDS)) {
            return;
        }

        $write = function () use ($id, $descriptor): void {
            $index = $this->index();
            $index[$id] = $descriptor;
            $this->cache->forever($this->prefix.'index', $index);
        };

        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            $write();

            return;
        }

        try {
            $store->lock($this->prefix.'index:lock', self::LOCK_SECONDS)->block(self::LOCK_WAIT_SECONDS, $write);
        } catch (LockTimeoutException) {
            // Retry the registration on the next request; the sample is still recorded.
            $this->cache->forget($this->prefix.$id.':seen');
        }
    }

    private function increment(string $key, int $by): void
    {
        $key = $this->prefix.$key;

        // Some stores (e.g. database) only increment existing keys.
        if ($this->cache->increment($key, $by) === false) {
            $this->cache->add($key, 0, null);
            $this->cache->increment($key, $by);
        }
    }

    private function int(string $key): int
    {
        $value = $this->cache->get($this->prefix.$key);

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return array<string, Descriptor>
     */
    private function index(): array
    {
        $index = $this->cache->get($this->prefix.'index');

        if (! is_array($index)) {
            return [];
        }

        /** @var array<string, Descriptor> $index */
        return $index;
    }

    /**
     * @param  array<string, string>  $labels
     */
    private static function seriesId(string $type, string $name, array $labels): string
    {
        ksort($labels);

        return $type.':'.$name.':'.md5((string) json_encode($labels));
    }
}
