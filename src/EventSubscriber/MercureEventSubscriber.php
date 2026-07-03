<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Event\DriftDetectedEvent;
use App\Event\DtoGeneratedEvent;
use App\Event\HealthStatusChangedEvent;
use App\Event\ThresholdExceededEvent;
use App\Service\Mercure\MercurePublisherServiceInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class MercureEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MercurePublisherServiceInterface $mercurePublisher,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            DriftDetectedEvent::class => 'onDriftDetected',
            HealthStatusChangedEvent::class => 'onHealthStatusChanged',
            ThresholdExceededEvent::class => 'onThresholdExceeded',
            DtoGeneratedEvent::class => 'onDtoGenerated',
        ];
    }

    public function onDriftDetected(DriftDetectedEvent $event): void
    {
        $this->mercurePublisher->publishDriftDetected($event->drift);
    }

    public function onHealthStatusChanged(HealthStatusChangedEvent $event): void
    {
        $this->mercurePublisher->publishHealthStatusChange(
            $event->host,
            $event->oldStatus,
            $event->newStatus,
        );
    }

    public function onThresholdExceeded(ThresholdExceededEvent $event): void
    {
        $this->mercurePublisher->publishRequestThresholdExceeded(
            $event->host,
            $event->metric,
            $event->value,
            $event->threshold,
        );
    }

    public function onDtoGenerated(DtoGeneratedEvent $event): void
    {
        $this->mercurePublisher->publishDtoGenerated($event->dto);
    }
}
