<?php

declare(strict_types=1);

namespace App\Platform\Observability\Metrics;

/**
 * Renders stored samples and runtime gauges in the Prometheus text
 * exposition format (version 0.0.4).
 */
final class PrometheusRenderer
{
    public const CONTENT_TYPE = 'text/plain; version=0.0.4; charset=utf-8';

    public function __construct(
        private readonly MetricsStore $store,
        private readonly RuntimeMetrics $runtime,
    ) {}

    public function render(): string
    {
        $snapshot = $this->store->snapshot();
        $lines = [];

        foreach ($snapshot['counters'] as $name => $series) {
            $lines[] = '# HELP '.$name.' '.(HttpMetrics::HELP[$name] ?? $name);
            $lines[] = '# TYPE '.$name.' counter';

            foreach ($series as $sample) {
                $lines[] = $name.self::labels($sample['labels']).' '.self::number($sample['value']);
            }
        }

        foreach ($snapshot['histograms'] as $name => $series) {
            $lines[] = '# HELP '.$name.' '.(HttpMetrics::HELP[$name] ?? $name);
            $lines[] = '# TYPE '.$name.' histogram';

            foreach ($series as $sample) {
                // Bucket counts from the store are already cumulative.
                foreach ($sample['buckets'] as $le => $count) {
                    $lines[] = $name.'_bucket'.self::labels($sample['labels'] + ['le' => (string) $le]).' '.$count;
                }

                $lines[] = $name.'_bucket'.self::labels($sample['labels'] + ['le' => '+Inf']).' '.$sample['count'];
                $lines[] = $name.'_sum'.self::labels($sample['labels']).' '.self::number($sample['sum']);
                $lines[] = $name.'_count'.self::labels($sample['labels']).' '.$sample['count'];
            }
        }

        foreach ($this->runtime->collect() as $gauge) {
            $lines[] = '# HELP '.$gauge['name'].' '.$gauge['help'];
            $lines[] = '# TYPE '.$gauge['name'].' gauge';
            $lines[] = $gauge['name'].self::labels($gauge['labels']).' '.self::number($gauge['value']);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, string>  $labels
     */
    private static function labels(array $labels): string
    {
        if ($labels === []) {
            return '';
        }

        $pairs = [];

        foreach ($labels as $key => $value) {
            $escaped = str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
            $pairs[] = $key.'="'.$escaped.'"';
        }

        return '{'.implode(',', $pairs).'}';
    }

    private static function number(float|int $value): string
    {
        if (is_int($value) || (floor($value) === $value && abs($value) < 1e15)) {
            return (string) (int) $value;
        }

        return (string) $value;
    }
}
