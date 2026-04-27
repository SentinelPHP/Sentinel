<?php

declare(strict_types=1);

namespace App\Service\Tracing;

use Psr\Log\LoggerInterface;

/**
 * A span implementation that logs trace data.
 *
 * This can be replaced with a real OpenTelemetry span when the SDK is integrated.
 * For now, it provides basic tracing functionality with log output.
 */
final class NoopSpan implements SpanInterface
{
    private float $startTime;
    private ?float $endTime = null;
    private string $status = 'unset';
    private ?string $statusDescription = null;

    /** @var array<string, string|int|float|bool> */
    private array $attributes = [];

    /** @var list<array{name: string, timestamp: float, attributes: array<string, string|int|float|bool>}> */
    private array $events = [];

    /**
     * @param array<string, string|int|float|bool> $initialAttributes
     */
    public function __construct(
        private string $traceId = '',
        private string $spanId = '',
        private readonly ?string $parentSpanId = null,
        private readonly string $name = '',
        private readonly string $serviceName = 'sentinel-php',
        array $initialAttributes = [],
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->startTime = microtime(true);
        $this->attributes = $initialAttributes;

        if ($this->traceId === '') {
            // Noop mode - generate placeholder IDs
            $this->traceId = str_repeat('0', 32);
            $this->spanId = str_repeat('0', 16);
        }
    }

    public function getTraceId(): string
    {
        return $this->traceId;
    }

    public function getSpanId(): string
    {
        return $this->spanId;
    }

    public function setAttribute(string $key, string|int|float|bool $value): self
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    public function setAttributes(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            $this->attributes[$key] = $value;
        }
        return $this;
    }

    public function addEvent(string $name, array $attributes = []): self
    {
        $this->events[] = [
            'name' => $name,
            'timestamp' => microtime(true),
            'attributes' => $attributes,
        ];
        return $this;
    }

    public function recordException(\Throwable $exception): self
    {
        $this->addEvent('exception', [
            'exception.type' => get_class($exception),
            'exception.message' => $exception->getMessage(),
            'exception.stacktrace' => $exception->getTraceAsString(),
        ]);
        $this->setStatus('error', $exception->getMessage());
        return $this;
    }

    public function setStatus(string $status, ?string $description = null): self
    {
        $this->status = $status;
        $this->statusDescription = $description;
        return $this;
    }

    public function end(): void
    {
        if ($this->endTime !== null) {
            return; // Already ended
        }

        $this->endTime = microtime(true);
        $durationMs = ($this->endTime - $this->startTime) * 1000;

        // Log the span data
        $this->logger?->debug('Span completed', [
            'trace_id' => $this->traceId,
            'span_id' => $this->spanId,
            'parent_span_id' => $this->parentSpanId,
            'name' => $this->name,
            'service' => $this->serviceName,
            'duration_ms' => round($durationMs, 3),
            'status' => $this->status,
            'status_description' => $this->statusDescription,
            'attributes' => $this->attributes,
            'events_count' => count($this->events),
        ]);
    }

    /**
     * Get span data for export.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'traceId' => $this->traceId,
            'spanId' => $this->spanId,
            'parentSpanId' => $this->parentSpanId,
            'name' => $this->name,
            'serviceName' => $this->serviceName,
            'startTime' => $this->startTime,
            'endTime' => $this->endTime,
            'durationMs' => $this->endTime !== null ? ($this->endTime - $this->startTime) * 1000 : null,
            'status' => $this->status,
            'statusDescription' => $this->statusDescription,
            'attributes' => $this->attributes,
            'events' => $this->events,
        ];
    }
}
