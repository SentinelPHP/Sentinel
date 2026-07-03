<?php

declare(strict_types=1);

namespace App\Service\Tracing;

use Psr\Log\LoggerInterface;

/**
 * Tracing service implementation.
 *
 * This is a lightweight implementation that can work standalone or integrate
 * with OpenTelemetry when the SDK is available. It provides trace context
 * propagation using W3C Trace Context format.
 */
final class TracingService implements TracingServiceInterface
{
    private ?string $traceId = null;
    private ?string $parentSpanId = null;
    private ?NoopSpan $currentSpan = null;

    /** @var list<NoopSpan> */
    private array $spanStack = [];

    public function __construct(
        private readonly bool $enabled = false,
        private readonly string $serviceName = 'sentinel-php',
        string $exporterEndpoint = '',
        private readonly ?LoggerInterface $logger = null,
    ) {
        // $exporterEndpoint reserved for future OpenTelemetry SDK integration
        unset($exporterEndpoint);
    }

    public function startSpan(string $name, array $attributes = []): SpanInterface
    {
        if (!$this->enabled) {
            return new NoopSpan();
        }

        // Generate IDs if not set
        if ($this->traceId === null) {
            $this->traceId = $this->generateTraceId();
        }

        $spanId = $this->generateSpanId();
        $parentId = $this->currentSpan?->getSpanId() ?? $this->parentSpanId;

        $span = new NoopSpan(
            $this->traceId,
            $spanId,
            $parentId,
            $name,
            $this->serviceName,
            $attributes,
            $this->logger,
        );

        $this->spanStack[] = $span;
        $this->currentSpan = $span;

        return $span;
    }

    public function getCurrentSpan(): ?SpanInterface
    {
        return $this->currentSpan;
    }

    public function getTraceId(): ?string
    {
        return $this->traceId;
    }

    public function getSpanId(): ?string
    {
        return $this->currentSpan?->getSpanId();
    }

    public function extractContext(array $headers): void
    {
        if (!$this->enabled) {
            return;
        }

        // W3C Trace Context format: traceparent header
        // Format: version-traceid-parentid-flags
        // Example: 00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01
        $traceparent = $headers['traceparent'] ?? $headers['Traceparent'] ?? null;

        if ($traceparent !== null) {
            $parts = explode('-', $traceparent);
            if (count($parts) === 4) {
                $this->traceId = $parts[1];
                $this->parentSpanId = $parts[2];
            }
        }
    }

    public function injectContext(): array
    {
        if (!$this->enabled || $this->traceId === null) {
            return [];
        }

        $spanId = $this->currentSpan?->getSpanId() ?? $this->generateSpanId();

        return [
            'traceparent' => sprintf('00-%s-%s-01', $this->traceId, $spanId),
        ];
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * End the current span and pop from stack.
     */
    public function endCurrentSpan(): void
    {
        if ($this->currentSpan !== null) {
            $this->currentSpan->end();
            array_pop($this->spanStack);
            $this->currentSpan = end($this->spanStack) ?: null;
        }
    }

    /**
     * Reset tracing state (useful between requests in long-running processes).
     */
    public function reset(): void
    {
        $this->traceId = null;
        $this->parentSpanId = null;
        $this->currentSpan = null;
        $this->spanStack = [];
    }

    private function generateTraceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function generateSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
