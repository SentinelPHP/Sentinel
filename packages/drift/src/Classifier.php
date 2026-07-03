<?php

declare(strict_types=1);

namespace SentinelPHP\Drift;

use SentinelPHP\Drift\Enum\DriftSeverity;
use SentinelPHP\Drift\Enum\DriftType;

final class Classifier implements ClassifierInterface
{
    private const PRIMITIVE_TYPES = ['string', 'integer', 'number', 'boolean', 'null'];
    private const COMPLEX_TYPES = ['object', 'array'];

    private const SEVERITY_ORDER = [
        'info' => 0,
        'warning' => 1,
        'critical' => 2,
    ];

    public function __construct(
        private readonly DriftSeverity $defaultThreshold = DriftSeverity::Warning,
    ) {
    }

    public function classify(
        DriftType $driftType,
        mixed $expected,
        mixed $actual,
        ?array $overrides = null,
    ): DriftSeverity {
        if ($overrides !== null && isset($overrides[$driftType->value])) {
            $overrideSeverity = DriftSeverity::tryFrom($overrides[$driftType->value]);
            if ($overrideSeverity !== null) {
                return $overrideSeverity;
            }
        }

        return match ($driftType) {
            DriftType::FieldRemoved => DriftSeverity::Critical,
            DriftType::TypeChanged => $this->classifyTypeChange($expected, $actual),
            DriftType::FieldAdded => DriftSeverity::Info,
            DriftType::StructureChanged => $this->classifyStructureChange($expected, $actual),
        };
    }

    public function shouldAlert(DriftSeverity $severity, ?DriftSeverity $threshold = null): bool
    {
        $threshold ??= $this->defaultThreshold;

        return self::SEVERITY_ORDER[$severity->value] >= self::SEVERITY_ORDER[$threshold->value];
    }

    private function classifyTypeChange(mixed $expected, mixed $actual): DriftSeverity
    {
        $expectedType = $this->normalizeType($expected);
        $actualType = $this->normalizeType($actual);

        if ($this->isComplexType($expectedType) && $this->isPrimitiveType($actualType)) {
            return DriftSeverity::Critical;
        }

        if ($this->isFormatChange($expected, $actual)) {
            return DriftSeverity::Info;
        }

        if ($this->areCompatibleTypes($expectedType, $actualType)) {
            return DriftSeverity::Warning;
        }

        return DriftSeverity::Warning;
    }

    private function classifyStructureChange(mixed $expected, mixed $actual): DriftSeverity
    {
        return DriftSeverity::Warning;
    }

    private function normalizeType(mixed $value): string
    {
        if (is_string($value)) {
            return strtolower($value);
        }

        if (is_array($value) && isset($value['type'])) {
            return $this->normalizeType($value['type']);
        }

        return 'unknown';
    }

    private function isFormatChange(mixed $expected, mixed $actual): bool
    {
        if (!is_array($expected) || !is_array($actual)) {
            return false;
        }

        $expectedType = $expected['type'] ?? null;
        $actualType = $actual['type'] ?? null;

        if ($expectedType !== $actualType) {
            return false;
        }

        $expectedFormat = $expected['format'] ?? null;
        $actualFormat = $actual['format'] ?? null;

        return $expectedFormat !== null && $actualFormat !== null && $expectedFormat !== $actualFormat;
    }

    private function isPrimitiveType(string $type): bool
    {
        return in_array($type, self::PRIMITIVE_TYPES, true);
    }

    private function isComplexType(string $type): bool
    {
        return in_array($type, self::COMPLEX_TYPES, true);
    }

    private function areCompatibleTypes(string $expected, string $actual): bool
    {
        if ($expected === 'number' && $actual === 'integer') {
            return true;
        }

        if ($expected === 'integer' && $actual === 'number') {
            return true;
        }

        return false;
    }
}
