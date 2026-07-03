<?php

declare(strict_types=1);

namespace App\Service\Metrics;

/**
 * Interface for collecting and exposing application metrics.
 */
interface MetricsCollectorInterface
{
    /**
     * Increment a counter metric.
     *
     * @param string $name Metric name
     * @param array<string, string> $labels Label key-value pairs
     * @param float $value Increment value (default 1)
     */
    public function incrementCounter(string $name, array $labels = [], float $value = 1): void;

    /**
     * Record a value in a histogram.
     *
     * @param string $name Metric name
     * @param float $value Observed value
     * @param array<string, string> $labels Label key-value pairs
     */
    public function recordHistogram(string $name, float $value, array $labels = []): void;

    /**
     * Set a gauge value.
     *
     * @param string $name Metric name
     * @param float $value Current value
     * @param array<string, string> $labels Label key-value pairs
     */
    public function setGauge(string $name, float $value, array $labels = []): void;

    /**
     * Get all metrics in Prometheus text format.
     */
    public function getPrometheusOutput(): string;
}
