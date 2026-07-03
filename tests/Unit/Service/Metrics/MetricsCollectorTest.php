<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Metrics;

use App\Service\Metrics\MetricsCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MetricsCollector::class)]
final class MetricsCollectorTest extends TestCase
{
    private MetricsCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new MetricsCollector();
    }

    #[Test]
    public function incrementCounterIncrementsValue(): void
    {
        $this->collector->incrementCounter('proxy_requests_total');
        $this->collector->incrementCounter('proxy_requests_total');
        $this->collector->incrementCounter('proxy_requests_total');

        $output = $this->collector->getPrometheusOutput();

        self::assertStringContainsString('sentinel_proxy_requests_total', $output);
        self::assertStringContainsString('3', $output);
    }

    #[Test]
    public function incrementCounterWithLabelsCreatesLabeledMetric(): void
    {
        $this->collector->incrementCounter('proxy_requests_total', ['method' => 'GET', 'status' => '200']);
        $this->collector->incrementCounter('proxy_requests_total', ['method' => 'POST', 'status' => '201']);

        $output = $this->collector->getPrometheusOutput();

        self::assertStringContainsString('method="GET"', $output);
        self::assertStringContainsString('method="POST"', $output);
    }

    #[Test]
    public function setGaugeSetsValue(): void
    {
        $this->collector->setGauge('active_tokens', 10);
        $this->collector->setGauge('active_tokens', 15);

        $output = $this->collector->getPrometheusOutput();

        self::assertStringContainsString('sentinel_active_tokens', $output);
        self::assertStringContainsString('15', $output);
    }

    #[Test]
    public function recordHistogramRecordsObservation(): void
    {
        $this->collector->recordHistogram('proxy_request_duration_seconds', 0.1);
        $this->collector->recordHistogram('proxy_request_duration_seconds', 0.5);
        $this->collector->recordHistogram('proxy_request_duration_seconds', 1.2);

        $output = $this->collector->getPrometheusOutput();

        self::assertStringContainsString('sentinel_proxy_request_duration_seconds', $output);
        self::assertStringContainsString('_bucket', $output);
        self::assertStringContainsString('_sum', $output);
        self::assertStringContainsString('_count', $output);
    }

    #[Test]
    public function getPrometheusOutputIncludesAllMetricTypes(): void
    {
        $this->collector->incrementCounter('proxy_requests_total');
        $this->collector->setGauge('active_tokens', 5);
        $this->collector->recordHistogram('proxy_request_duration_seconds', 0.5);

        $output = $this->collector->getPrometheusOutput();

        self::assertStringContainsString('# TYPE sentinel_proxy_requests_total counter', $output);
        self::assertStringContainsString('# TYPE sentinel_active_tokens gauge', $output);
        self::assertStringContainsString('# TYPE sentinel_proxy_request_duration_seconds histogram', $output);
    }

    #[Test]
    public function resetClearsAllMetrics(): void
    {
        $this->collector->incrementCounter('proxy_requests_total');
        $this->collector->setGauge('active_tokens', 5);

        $this->collector->reset();

        $output = $this->collector->getPrometheusOutput();

        self::assertSame("\n", $output);
    }

    #[Test]
    public function getPrometheusOutputIncludesHelpText(): void
    {
        $this->collector->incrementCounter('proxy_requests_total');

        $output = $this->collector->getPrometheusOutput();

        self::assertStringContainsString('# HELP sentinel_proxy_requests_total', $output);
    }

    #[Test]
    public function incrementCounterWithDifferentLabelsTrackedSeparately(): void
    {
        $this->collector->incrementCounter('proxy_requests_total', ['status' => '200']);
        $this->collector->incrementCounter('proxy_requests_total', ['status' => '200']);
        $this->collector->incrementCounter('proxy_requests_total', ['status' => '500']);

        $output = $this->collector->getPrometheusOutput();

        self::assertStringContainsString('status="200"} 2', $output);
        self::assertStringContainsString('status="500"} 1', $output);
    }

    #[Test]
    public function setGaugeWithLabelsTrackedSeparately(): void
    {
        $this->collector->setGauge('schemas_count', 10, ['type' => 'request']);
        $this->collector->setGauge('schemas_count', 5, ['type' => 'response']);

        $output = $this->collector->getPrometheusOutput();

        self::assertStringContainsString('type="request"} 10', $output);
        self::assertStringContainsString('type="response"} 5', $output);
    }

    #[Test]
    public function histogramBucketsCalculatedCorrectly(): void
    {
        $this->collector->recordHistogram('proxy_request_duration_seconds', 0.001);
        $this->collector->recordHistogram('proxy_request_duration_seconds', 0.05);
        $this->collector->recordHistogram('proxy_request_duration_seconds', 0.5);
        $this->collector->recordHistogram('proxy_request_duration_seconds', 2.0);

        $output = $this->collector->getPrometheusOutput();

        self::assertStringContainsString('le="0.005"', $output);
        self::assertStringContainsString('le="0.1"', $output);
        self::assertStringContainsString('le="+Inf"', $output);
    }

    #[Test]
    public function disabledCollectorDoesNotRecordMetrics(): void
    {
        $collector = new MetricsCollector(enabled: false);

        $collector->incrementCounter('proxy_requests_total');
        $collector->setGauge('active_tokens', 5);
        $collector->recordHistogram('proxy_request_duration_seconds', 0.5);

        $output = $collector->getPrometheusOutput();

        self::assertSame("\n", $output);
    }

    #[Test]
    public function labelsAreSortedAlphabetically(): void
    {
        $this->collector->incrementCounter('proxy_requests_total', ['z_label' => 'z', 'a_label' => 'a']);

        $output = $this->collector->getPrometheusOutput();

        $position_a = strpos($output, 'a_label');
        $position_z = strpos($output, 'z_label');

        self::assertNotFalse($position_a);
        self::assertNotFalse($position_z);
        self::assertLessThan($position_z, $position_a);
    }

    #[Test]
    public function labelValuesAreEscaped(): void
    {
        $this->collector->incrementCounter('proxy_requests_total', ['path' => '/users/"test"']);

        $output = $this->collector->getPrometheusOutput();

        self::assertStringContainsString('\\"test\\"', $output);
    }

    #[Test]
    public function incrementCounterWithCustomValue(): void
    {
        $this->collector->incrementCounter('proxy_requests_total', [], 5);

        $output = $this->collector->getPrometheusOutput();

        self::assertStringContainsString('sentinel_proxy_requests_total 5', $output);
    }

    #[Test]
    public function histogramSumAndCountAreCorrect(): void
    {
        $this->collector->recordHistogram('proxy_request_duration_seconds', 0.1);
        $this->collector->recordHistogram('proxy_request_duration_seconds', 0.2);
        $this->collector->recordHistogram('proxy_request_duration_seconds', 0.3);

        $output = $this->collector->getPrometheusOutput();

        self::assertStringContainsString('_sum 0.6', $output);
        self::assertStringContainsString('_count 3', $output);
    }
}
