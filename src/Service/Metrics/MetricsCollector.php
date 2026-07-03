<?php

declare(strict_types=1);

namespace App\Service\Metrics;

/**
 * In-memory metrics collector with Prometheus text format export.
 *
 * This implementation stores metrics in memory and is suitable for
 * single-instance deployments. For multi-instance deployments,
 * consider using Redis-backed storage or a push gateway.
 */
final class MetricsCollector implements MetricsCollectorInterface
{
    private const NAMESPACE = 'sentinel';

    /** @var array<string, array{type: string, help: string, values: array<string, float>}> */
    private array $counters = [];

    /** @var array<string, array{type: string, help: string, buckets: list<float>, values: array<string, array{bucket_counts: array<string, int>, sum: float, count: int}>}> */
    private array $histograms = [];

    /** @var array<string, array{type: string, help: string, values: array<string, float>}> */
    private array $gauges = [];

    /** @var array<string, string> */
    private array $metricHelp = [
        'proxy_requests_total' => 'Total number of proxy requests',
        'proxy_request_duration_seconds' => 'Proxy request duration in seconds',
        'upstream_response_time_seconds' => 'Upstream API response time in seconds',
        'schema_operations_total' => 'Total schema operations (learn, validate, merge)',
        'schema_validation_duration_seconds' => 'Schema validation duration in seconds',
        'drift_detected_total' => 'Total number of schema drifts detected',
        'alerts_sent_total' => 'Total alerts sent by channel and status',
        'active_tokens' => 'Number of active API tokens',
        'schemas_count' => 'Number of schemas by type',
        'circuit_breaker_state' => 'Circuit breaker state (0=closed, 1=open, 2=half-open)',
        'rate_limit_hits_total' => 'Total rate limit hits',
        'cache_hits_total' => 'Cache hits',
        'cache_misses_total' => 'Cache misses',
        'redis_operations_total' => 'Total Redis operations',
        'redis_operation_duration_seconds' => 'Redis operation duration in seconds',
        'database_queries_total' => 'Total database queries',
        'database_query_duration_seconds' => 'Database query duration in seconds',
    ];

    /** @var list<float> */
    private array $defaultBuckets = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10];

    public function __construct(
        private readonly bool $enabled = true,
    ) {
    }

    public function incrementCounter(string $name, array $labels = [], float $value = 1): void
    {
        if (!$this->enabled) {
            return;
        }

        $fullName = $this->getFullName($name);
        $labelKey = $this->serializeLabels($labels);

        if (!isset($this->counters[$fullName])) {
            $this->counters[$fullName] = [
                'type' => 'counter',
                'help' => $this->metricHelp[$name] ?? "Counter metric {$name}",
                'values' => [],
            ];
        }

        if (!isset($this->counters[$fullName]['values'][$labelKey])) {
            $this->counters[$fullName]['values'][$labelKey] = 0;
        }

        $this->counters[$fullName]['values'][$labelKey] += $value;
    }

    public function recordHistogram(string $name, float $value, array $labels = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $fullName = $this->getFullName($name);
        $labelKey = $this->serializeLabels($labels);

        if (!isset($this->histograms[$fullName])) {
            $this->histograms[$fullName] = [
                'type' => 'histogram',
                'help' => $this->metricHelp[$name] ?? "Histogram metric {$name}",
                'buckets' => $this->defaultBuckets,
                'values' => [],
            ];
        }

        if (!isset($this->histograms[$fullName]['values'][$labelKey])) {
            $this->histograms[$fullName]['values'][$labelKey] = [
                'bucket_counts' => array_fill_keys(
                    array_map(fn ($b) => (string) $b, $this->defaultBuckets),
                    0
                ),
                'sum' => 0,
                'count' => 0,
            ];
            $this->histograms[$fullName]['values'][$labelKey]['bucket_counts']['+Inf'] = 0;
        }

        $histData = &$this->histograms[$fullName]['values'][$labelKey];
        $histData['sum'] += $value;
        $histData['count']++;

        foreach ($this->defaultBuckets as $bucket) {
            if ($value <= $bucket) {
                $histData['bucket_counts'][(string) $bucket]++;
            }
        }
        $histData['bucket_counts']['+Inf']++;
    }

    public function setGauge(string $name, float $value, array $labels = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $fullName = $this->getFullName($name);
        $labelKey = $this->serializeLabels($labels);

        if (!isset($this->gauges[$fullName])) {
            $this->gauges[$fullName] = [
                'type' => 'gauge',
                'help' => $this->metricHelp[$name] ?? "Gauge metric {$name}",
                'values' => [],
            ];
        }

        $this->gauges[$fullName]['values'][$labelKey] = $value;
    }

    public function getPrometheusOutput(): string
    {
        $output = [];

        // Counters
        foreach ($this->counters as $name => $metric) {
            $output[] = "# HELP {$name} {$metric['help']}";
            $output[] = "# TYPE {$name} {$metric['type']}";
            foreach ($metric['values'] as $labelKey => $value) {
                $labels = $labelKey !== '' ? "{{$labelKey}}" : '';
                $output[] = "{$name}{$labels} {$value}";
            }
        }

        // Histograms
        foreach ($this->histograms as $name => $metric) {
            $output[] = "# HELP {$name} {$metric['help']}";
            $output[] = "# TYPE {$name} {$metric['type']}";
            foreach ($metric['values'] as $labelKey => $histData) {
                $baseLabels = $labelKey !== '' ? "{$labelKey}," : '';
                $cumulativeCount = 0;

                foreach ($metric['buckets'] as $bucket) {
                    $cumulativeCount += $histData['bucket_counts'][(string) $bucket];
                    $output[] = "{$name}_bucket{{$baseLabels}le=\"{$bucket}\"} {$cumulativeCount}";
                }
                $cumulativeCount += $histData['bucket_counts']['+Inf'] - $histData['count'];
                $output[] = "{$name}_bucket{{$baseLabels}le=\"+Inf\"} {$histData['count']}";
                
                $sumLabels = $labelKey !== '' ? "{{$labelKey}}" : '';
                $output[] = "{$name}_sum{$sumLabels} {$histData['sum']}";
                $output[] = "{$name}_count{$sumLabels} {$histData['count']}";
            }
        }

        // Gauges
        foreach ($this->gauges as $name => $metric) {
            $output[] = "# HELP {$name} {$metric['help']}";
            $output[] = "# TYPE {$name} {$metric['type']}";
            foreach ($metric['values'] as $labelKey => $value) {
                $labels = $labelKey !== '' ? "{{$labelKey}}" : '';
                $output[] = "{$name}{$labels} {$value}";
            }
        }

        return implode("\n", $output) . "\n";
    }

    /**
     * Reset all metrics (useful for testing).
     */
    public function reset(): void
    {
        $this->counters = [];
        $this->histograms = [];
        $this->gauges = [];
    }

    private function getFullName(string $name): string
    {
        return self::NAMESPACE . '_' . $name;
    }

    /**
     * @param array<string, string> $labels
     */
    private function serializeLabels(array $labels): string
    {
        if (empty($labels)) {
            return '';
        }

        ksort($labels);
        $parts = [];
        foreach ($labels as $key => $value) {
            $escapedValue = str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
            $parts[] = "{$key}=\"{$escapedValue}\"";
        }

        return implode(',', $parts);
    }
}
