<?php

declare(strict_types=1);

namespace App\Service\Tracing;

/**
 * Interface for distributed tracing operations.
 */
interface TracingServiceInterface
{
    /**
     * Start a new span.
     *
     * @param string $name Span name
     * @param array<string, string> $attributes Initial attributes
     * @return SpanInterface The created span
     */
    public function startSpan(string $name, array $attributes = []): SpanInterface;

    /**
     * Get the current active span, if any.
     */
    public function getCurrentSpan(): ?SpanInterface;

    /**
     * Get the current trace ID.
     */
    public function getTraceId(): ?string;

    /**
     * Get the current span ID.
     */
    public function getSpanId(): ?string;

    /**
     * Extract trace context from incoming request headers.
     *
     * @param array<string, string> $headers Request headers
     */
    public function extractContext(array $headers): void;

    /**
     * Inject trace context into outgoing request headers.
     *
     * @return array<string, string> Headers to add to outgoing request
     */
    public function injectContext(): array;

    /**
     * Check if tracing is enabled.
     */
    public function isEnabled(): bool;
}
