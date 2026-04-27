<?php

declare(strict_types=1);

namespace App\Service\Tracing;

/**
 * Interface representing a trace span.
 */
interface SpanInterface
{
    /**
     * Get the span's trace ID.
     */
    public function getTraceId(): string;

    /**
     * Get the span ID.
     */
    public function getSpanId(): string;

    /**
     * Set an attribute on the span.
     */
    public function setAttribute(string $key, string|int|float|bool $value): self;

    /**
     * Set multiple attributes at once.
     *
     * @param array<string, string|int|float|bool> $attributes
     */
    public function setAttributes(array $attributes): self;

    /**
     * Add an event to the span.
     *
     * @param string $name Event name
     * @param array<string, string|int|float|bool> $attributes Event attributes
     */
    public function addEvent(string $name, array $attributes = []): self;

    /**
     * Record an exception on the span.
     */
    public function recordException(\Throwable $exception): self;

    /**
     * Set the span status.
     *
     * @param string $status 'ok', 'error', or 'unset'
     * @param string|null $description Optional description for errors
     */
    public function setStatus(string $status, ?string $description = null): self;

    /**
     * End the span.
     */
    public function end(): void;
}
