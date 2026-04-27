<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\MetricsSubscriber;
use App\Service\Metrics\MetricsCollectorInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

#[CoversClass(MetricsSubscriber::class)]
#[AllowMockObjectsWithoutExpectations]
final class MetricsSubscriberTest extends TestCase
{
    private MetricsCollectorInterface&MockObject $metrics;
    private MetricsSubscriber $subscriber;
    private HttpKernelInterface&MockObject $kernel;

    protected function setUp(): void
    {
        $this->metrics = $this->createMock(MetricsCollectorInterface::class);
        $this->subscriber = new MetricsSubscriber($this->metrics);
        $this->kernel = $this->createMock(HttpKernelInterface::class);
    }

    #[Test]
    public function getSubscribedEventsReturnsKernelEvents(): void
    {
        $events = MetricsSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(KernelEvents::REQUEST, $events);
        self::assertArrayHasKey(KernelEvents::RESPONSE, $events);
        self::assertArrayHasKey(KernelEvents::TERMINATE, $events);
    }

    #[Test]
    public function onRequestSetsStartTimeAttribute(): void
    {
        $request = new Request();
        $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber->onRequest($event);

        self::assertTrue($request->attributes->has('_metrics_start_time'));
        self::assertIsFloat($request->attributes->get('_metrics_start_time'));
    }

    #[Test]
    public function onRequestIgnoresSubRequests(): void
    {
        $request = new Request();
        $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::SUB_REQUEST);

        $this->subscriber->onRequest($event);

        self::assertFalse($request->attributes->has('_metrics_start_time'));
    }

    #[Test]
    public function onResponseRecordsMetrics(): void
    {
        $request = new Request();
        $request->attributes->set('_route', 'test_route');
        $request->attributes->set('_metrics_start_time', microtime(true) - 0.1);

        $response = new Response('', 200);
        $event = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

        $this->metrics->expects($this->once())
            ->method('recordHistogram')
            ->with(
                'http_request_duration_seconds',
                $this->isFloat(),
                $this->callback(function (array $labels): bool {
                    return $labels['method'] === 'GET'
                        && $labels['route'] === 'test_route'
                        && $labels['status'] === '200';
                })
            );

        $this->metrics->expects($this->once())
            ->method('incrementCounter')
            ->with(
                'http_requests_total',
                $this->callback(function (array $labels): bool {
                    return $labels['method'] === 'GET'
                        && $labels['route'] === 'test_route'
                        && $labels['status'] === '200';
                })
            );

        $this->subscriber->onResponse($event);
    }

    #[Test]
    public function onResponseIgnoresSubRequests(): void
    {
        $request = new Request();
        $response = new Response();
        $event = new ResponseEvent($this->kernel, $request, HttpKernelInterface::SUB_REQUEST, $response);

        $this->metrics->expects($this->never())->method('recordHistogram');
        $this->metrics->expects($this->never())->method('incrementCounter');

        $this->subscriber->onResponse($event);
    }

    #[Test]
    public function onResponseSkipsMetricsRoute(): void
    {
        $request = new Request();
        $request->attributes->set('_route', 'metrics');
        $request->attributes->set('_metrics_start_time', microtime(true));

        $response = new Response();
        $event = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

        $this->metrics->expects($this->never())->method('recordHistogram');
        $this->metrics->expects($this->never())->method('incrementCounter');

        $this->subscriber->onResponse($event);
    }

    #[Test]
    public function onResponseRecordsErrorMetricsFor4xxStatus(): void
    {
        $request = new Request();
        $request->attributes->set('_route', 'test_route');
        $request->attributes->set('_metrics_start_time', microtime(true));

        $response = new Response('', 404);
        $event = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

        $incrementCalls = [];
        $this->metrics->method('incrementCounter')
            ->willReturnCallback(function (string $name, array $labels) use (&$incrementCalls): void {
                $incrementCalls[] = $name;
            });

        $this->subscriber->onResponse($event);

        self::assertContains('http_requests_total', $incrementCalls);
        self::assertContains('http_errors_total', $incrementCalls);
    }

    #[Test]
    public function onResponseRecordsErrorMetricsFor5xxStatus(): void
    {
        $request = new Request();
        $request->attributes->set('_route', 'test_route');
        $request->attributes->set('_metrics_start_time', microtime(true));

        $response = new Response('', 500);
        $event = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

        $incrementCalls = [];
        $this->metrics->method('incrementCounter')
            ->willReturnCallback(function (string $name, array $labels) use (&$incrementCalls): void {
                $incrementCalls[] = $name;
            });

        $this->subscriber->onResponse($event);

        self::assertContains('http_errors_total', $incrementCalls);
    }

    #[Test]
    public function onResponseDoesNotRecordErrorMetricsFor2xxStatus(): void
    {
        $request = new Request();
        $request->attributes->set('_route', 'test_route');
        $request->attributes->set('_metrics_start_time', microtime(true));

        $response = new Response('', 200);
        $event = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

        $incrementCalls = [];
        $this->metrics->method('incrementCounter')
            ->willReturnCallback(function (string $name, array $labels) use (&$incrementCalls): void {
                $incrementCalls[] = $name;
            });

        $this->subscriber->onResponse($event);

        self::assertNotContains('http_errors_total', $incrementCalls);
    }

    #[Test]
    public function onResponseUsesUnknownRouteWhenNotSet(): void
    {
        $request = new Request();
        $request->attributes->set('_metrics_start_time', microtime(true));

        $response = new Response();
        $event = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

        $this->metrics->expects($this->once())
            ->method('incrementCounter')
            ->with(
                'http_requests_total',
                $this->callback(function (array $labels): bool {
                    return $labels['route'] === 'unknown';
                })
            );

        $this->subscriber->onResponse($event);
    }

    #[Test]
    public function onTerminateDoesNotThrow(): void
    {
        $request = new Request();
        $response = new Response();
        $event = new TerminateEvent($this->kernel, $request, $response);

        $this->subscriber->onTerminate($event);

        $this->expectNotToPerformAssertions();
    }
}
