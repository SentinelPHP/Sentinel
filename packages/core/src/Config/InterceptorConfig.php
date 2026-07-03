<?php

declare(strict_types=1);

namespace SentinelPHP\Core\Config;

/**
 * Configuration for the SentinelInterceptor.
 */
final readonly class InterceptorConfig
{
    /**
     * @param bool $redactPii Whether to redact PII from request/response bodies before storage
     * @param bool $generateSchemas Whether to generate JSON schemas from responses
     * @param bool $captureRequestBody Whether to capture request bodies
     * @param bool $captureResponseBody Whether to capture response bodies
     * @param bool $captureHeaders Whether to capture request/response headers
     * @param list<string> $redactFieldPaths JSON paths to always redact (e.g., ['password', 'secret'])
     */
    public function __construct(
        public bool $redactPii = true,
        public bool $generateSchemas = false,
        public bool $captureRequestBody = true,
        public bool $captureResponseBody = true,
        public bool $captureHeaders = true,
        public array $redactFieldPaths = [],
    ) {
    }

    public static function default(): self
    {
        return new self();
    }

    public static function minimal(): self
    {
        return new self(
            redactPii: false,
            generateSchemas: false,
            captureRequestBody: false,
            captureResponseBody: false,
            captureHeaders: false,
        );
    }

    public static function full(): self
    {
        return new self(
            redactPii: true,
            generateSchemas: true,
            captureRequestBody: true,
            captureResponseBody: true,
            captureHeaders: true,
        );
    }
}
