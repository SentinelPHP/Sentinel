<?php

declare(strict_types=1);

namespace SentinelPHP\Drift;

use SentinelPHP\Drift\Enum\DriftSeverity;
use SentinelPHP\Drift\Enum\DriftType;

interface ClassifierInterface
{
    /**
     * Classify the severity of a schema drift.
     *
     * @param DriftType $driftType The type of drift detected
     * @param mixed $expected The expected value (from schema)
     * @param mixed $actual The actual value (from payload)
     * @param array<string, string>|null $overrides Optional severity overrides by drift type
     * @return DriftSeverity The classified severity
     */
    public function classify(
        DriftType $driftType,
        mixed $expected,
        mixed $actual,
        ?array $overrides = null,
    ): DriftSeverity;

    /**
     * Determine if a drift should trigger an alert based on severity threshold.
     *
     * @param DriftSeverity $severity The severity of the drift
     * @param DriftSeverity|null $threshold Minimum severity threshold (defaults to Warning)
     */
    public function shouldAlert(DriftSeverity $severity, ?DriftSeverity $threshold = null): bool;
}
