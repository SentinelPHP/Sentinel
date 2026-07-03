<?php

declare(strict_types=1);

namespace SentinelPHP\Core\Tests\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SentinelPHP\Core\Config\InterceptorConfig;

#[CoversClass(InterceptorConfig::class)]
final class InterceptorConfigTest extends TestCase
{
    #[Test]
    public function it_creates_with_default_values(): void
    {
        $config = new InterceptorConfig();

        self::assertTrue($config->redactPii);
        self::assertFalse($config->generateSchemas);
        self::assertTrue($config->captureRequestBody);
        self::assertTrue($config->captureResponseBody);
        self::assertTrue($config->captureHeaders);
        self::assertSame([], $config->redactFieldPaths);
    }

    #[Test]
    public function it_creates_with_custom_values(): void
    {
        $config = new InterceptorConfig(
            redactPii: false,
            generateSchemas: true,
            captureRequestBody: false,
            captureResponseBody: false,
            captureHeaders: false,
            redactFieldPaths: ['password', 'secret'],
        );

        self::assertFalse($config->redactPii);
        self::assertTrue($config->generateSchemas);
        self::assertFalse($config->captureRequestBody);
        self::assertFalse($config->captureResponseBody);
        self::assertFalse($config->captureHeaders);
        self::assertSame(['password', 'secret'], $config->redactFieldPaths);
    }

    #[Test]
    public function it_creates_default_preset(): void
    {
        $config = InterceptorConfig::default();

        self::assertTrue($config->redactPii);
        self::assertFalse($config->generateSchemas);
        self::assertTrue($config->captureRequestBody);
        self::assertTrue($config->captureResponseBody);
        self::assertTrue($config->captureHeaders);
    }

    #[Test]
    public function it_creates_minimal_preset(): void
    {
        $config = InterceptorConfig::minimal();

        self::assertFalse($config->redactPii);
        self::assertFalse($config->generateSchemas);
        self::assertFalse($config->captureRequestBody);
        self::assertFalse($config->captureResponseBody);
        self::assertFalse($config->captureHeaders);
    }

    #[Test]
    public function it_creates_full_preset(): void
    {
        $config = InterceptorConfig::full();

        self::assertTrue($config->redactPii);
        self::assertTrue($config->generateSchemas);
        self::assertTrue($config->captureRequestBody);
        self::assertTrue($config->captureResponseBody);
        self::assertTrue($config->captureHeaders);
    }
}
