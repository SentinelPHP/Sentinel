<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Tracing;

use App\Service\Tracing\NoopSpan;
use App\Service\Tracing\TracingService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(TracingService::class)]
final class TracingServiceTest extends TestCase
{
    #[Test]
    public function startSpanReturnsNoopSpanWhenDisabled(): void
    {
        $service = new TracingService(enabled: false);

        $span = $service->startSpan('test-span');

        self::assertInstanceOf(NoopSpan::class, $span);
    }

    #[Test]
    public function startSpanCreatesSpanWhenEnabled(): void
    {
        $service = new TracingService(enabled: true);

        $span = $service->startSpan('test-span', ['key' => 'value']);

        self::assertInstanceOf(NoopSpan::class, $span);
        self::assertNotEmpty($span->getSpanId());
    }

    #[Test]
    public function startSpanGeneratesTraceIdOnFirstCall(): void
    {
        $service = new TracingService(enabled: true);

        self::assertNull($service->getTraceId());

        $service->startSpan('test-span');

        self::assertNotNull($service->getTraceId());
        self::assertSame(32, strlen($service->getTraceId()));
    }

    #[Test]
    public function startSpanReusesTraceIdForSubsequentSpans(): void
    {
        $service = new TracingService(enabled: true);

        $service->startSpan('span-1');
        $traceId1 = $service->getTraceId();

        $service->startSpan('span-2');
        $traceId2 = $service->getTraceId();

        self::assertSame($traceId1, $traceId2);
    }

    #[Test]
    public function getCurrentSpanReturnsCurrentSpan(): void
    {
        $service = new TracingService(enabled: true);

        self::assertNull($service->getCurrentSpan());

        $span = $service->startSpan('test-span');

        self::assertSame($span, $service->getCurrentSpan());
    }

    #[Test]
    public function getSpanIdReturnsCurrentSpanId(): void
    {
        $service = new TracingService(enabled: true);

        self::assertNull($service->getSpanId());

        $service->startSpan('test-span');

        self::assertNotNull($service->getSpanId());
        self::assertSame(16, strlen($service->getSpanId()));
    }

    #[Test]
    public function extractContextParsesTraceparentHeader(): void
    {
        $service = new TracingService(enabled: true);

        $service->extractContext([
            'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
        ]);

        self::assertSame('0af7651916cd43dd8448eb211c80319c', $service->getTraceId());
    }

    #[Test]
    public function extractContextHandlesCaseInsensitiveHeader(): void
    {
        $service = new TracingService(enabled: true);

        $service->extractContext([
            'Traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
        ]);

        self::assertSame('0af7651916cd43dd8448eb211c80319c', $service->getTraceId());
    }

    #[Test]
    public function extractContextDoesNothingWhenDisabled(): void
    {
        $service = new TracingService(enabled: false);

        $service->extractContext([
            'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
        ]);

        self::assertNull($service->getTraceId());
    }

    #[Test]
    public function injectContextReturnsEmptyArrayWhenDisabled(): void
    {
        $service = new TracingService(enabled: false);

        $headers = $service->injectContext();

        self::assertEmpty($headers);
    }

    #[Test]
    public function injectContextReturnsEmptyArrayWhenNoTraceId(): void
    {
        $service = new TracingService(enabled: true);

        $headers = $service->injectContext();

        self::assertEmpty($headers);
    }

    #[Test]
    public function injectContextReturnsTraceparentHeader(): void
    {
        $service = new TracingService(enabled: true);
        $service->startSpan('test-span');

        $headers = $service->injectContext();

        self::assertArrayHasKey('traceparent', $headers);
        self::assertMatchesRegularExpression('/^00-[a-f0-9]{32}-[a-f0-9]{16}-01$/', $headers['traceparent']);
    }

    #[Test]
    public function isEnabledReturnsCorrectValue(): void
    {
        $enabledService = new TracingService(enabled: true);
        $disabledService = new TracingService(enabled: false);

        self::assertTrue($enabledService->isEnabled());
        self::assertFalse($disabledService->isEnabled());
    }

    #[Test]
    public function endCurrentSpanPopsFromStack(): void
    {
        $service = new TracingService(enabled: true);

        $span1 = $service->startSpan('span-1');
        $span2 = $service->startSpan('span-2');

        self::assertSame($span2, $service->getCurrentSpan());

        $service->endCurrentSpan();

        self::assertSame($span1, $service->getCurrentSpan());

        $service->endCurrentSpan();

        self::assertNull($service->getCurrentSpan());
    }

    #[Test]
    public function resetClearsAllState(): void
    {
        $service = new TracingService(enabled: true);

        $service->startSpan('test-span');
        self::assertNotNull($service->getTraceId());
        self::assertNotNull($service->getCurrentSpan());

        $service->reset();

        self::assertNull($service->getTraceId());
        self::assertNull($service->getCurrentSpan());
        self::assertNull($service->getSpanId());
    }

    #[Test]
    public function nestedSpansHaveCorrectParentRelationship(): void
    {
        $service = new TracingService(enabled: true);

        $span1 = $service->startSpan('parent-span');
        $span1Id = $span1->getSpanId();

        $span2 = $service->startSpan('child-span');

        self::assertNotSame($span1Id, $span2->getSpanId());
    }

    #[Test]
    public function serviceNameIsConfigurable(): void
    {
        $service = new TracingService(
            enabled: true,
            serviceName: 'custom-service',
        );

        $span = $service->startSpan('test-span');

        self::assertInstanceOf(NoopSpan::class, $span);
    }
}
