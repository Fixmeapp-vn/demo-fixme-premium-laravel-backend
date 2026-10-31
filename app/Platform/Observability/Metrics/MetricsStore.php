<?php

declare(strict_types=1);

namespace App\Platform\Observability\Metrics;

/**
 * Storage for metric samples that must survive across PHP requests.
 *
 * @phpstan-type Labels array<string, string>
 * @phpstan-type CounterSeries array{labels: Labels, value: int}
 * @phpstan-type HistogramSeries array{labels: Labels, buckets: array<string, int>, sum: float, count: int}
 * @phpstan-type Snapshot array{counters: array<string, list<CounterSeries>>, histograms: array<string, list<HistogramSeries>>}
 */
interface MetricsStore
{
    /**
     * @param  array<string, string>  $labels
     */
    public function incrementCounter(string $name, array $labels, int $by = 1): void;

    /**
     * @param  array<string, string>  $labels
     * @param  list<float|int>  $buckets  Upper bounds, ascending (seconds).
     */
    public function observeHistogram(string $name, array $labels, float $value, array $buckets): void;

    /**
     * Current samples. Histogram bucket counts are cumulative (Prometheus "le" semantics).
     *
     * @return Snapshot
     */
    public function snapshot(): array;

    public function flush(): void;
}
