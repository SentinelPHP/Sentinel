<?php

declare(strict_types=1);

namespace App\Logging;

use App\Service\Tracing\TracingServiceInterface;
use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;

/**
 * JSON formatter that includes trace context in log entries.
 *
 * This enables correlation between logs and distributed traces.
 */
final class TracingJsonFormatter extends JsonFormatter
{
    public function __construct(
        private readonly ?TracingServiceInterface $tracingService = null,
        int $batchMode = self::BATCH_MODE_JSON,
        bool $appendNewline = true,
        bool $ignoreEmptyContextAndExtra = false,
        bool $includeStacktraces = false,
    ) {
        parent::__construct($batchMode, $appendNewline, $ignoreEmptyContextAndExtra, $includeStacktraces);
    }

    public function format(LogRecord $record): string
    {
        /** @var array<string, mixed> $normalized */
        $normalized = $this->normalizeRecord($record);

        // Add trace context if available
        if ($this->tracingService !== null && $this->tracingService->isEnabled()) {
            $traceId = $this->tracingService->getTraceId();
            $spanId = $this->tracingService->getSpanId();

            if ($traceId !== null) {
                $normalized['trace_id'] = $traceId;
            }
            if ($spanId !== null) {
                $normalized['span_id'] = $spanId;
            }
        }

        // Reorder fields for better readability
        $ordered = $this->reorderFields($normalized);

        return $this->toJson($ordered, true) . ($this->appendNewline ? "\n" : '');
    }

    /**
     * Reorder fields for consistent, readable output.
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function reorderFields(array $record): array
    {
        $priority = [
            'datetime',
            'level_name',
            'message',
            'trace_id',
            'span_id',
            'channel',
            'context',
            'extra',
        ];

        /** @var array<string, mixed> $ordered */
        $ordered = [];
        foreach ($priority as $key) {
            if (array_key_exists($key, $record)) {
                $ordered[$key] = $record[$key];
                unset($record[$key]);
            }
        }

        // Append any remaining fields
        return array_merge($ordered, $record);
    }
}
