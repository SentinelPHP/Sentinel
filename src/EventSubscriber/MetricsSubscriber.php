<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Metrics\MetricsCollectorInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Collects HTTP request metrics for Prometheus export.
 */
final class MetricsSubscriber implements EventSubscriberInterface
{
    private const REQUEST_START_TIME_ATTR = '_metrics_start_time';

    public function __construct(
        private readonly MetricsCollectorInterface $metrics,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 1000],
            KernelEvents::RESPONSE => ['onResponse', -1000],
            KernelEvents::TERMINATE => ['onTerminate', -1000],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $event->getRequest()->attributes->set(
            self::REQUEST_START_TIME_ATTR,
            microtime(true)
        );
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();
        /** @var string $route */
        $route = $request->attributes->get('_route', 'unknown');

        // Skip metrics endpoint to avoid recursion
        if ($route === 'metrics') {
            return;
        }

        $startTime = $request->attributes->get(self::REQUEST_START_TIME_ATTR);
        $method = $request->getMethod();
        $status = (string) $response->getStatusCode();

        if (is_float($startTime)) {
            $duration = microtime(true) - $startTime;

            // Record request duration
            $this->metrics->recordHistogram(
                'http_request_duration_seconds',
                $duration,
                [
                    'method' => $method,
                    'route' => $route,
                    'status' => $status,
                ]
            );
        }

        // Increment request counter
        $this->metrics->incrementCounter(
            'http_requests_total',
            [
                'method' => $method,
                'route' => $route,
                'status' => $status,
            ]
        );

        // Track errors
        if ($response->getStatusCode() >= 400) {
            $this->metrics->incrementCounter(
                'http_errors_total',
                [
                    'method' => $method,
                    'route' => $route,
                    'status' => $status,
                ]
            );
        }
    }

    public function onTerminate(TerminateEvent $event): void
    {
        // Additional cleanup or final metrics can be recorded here
    }
}
