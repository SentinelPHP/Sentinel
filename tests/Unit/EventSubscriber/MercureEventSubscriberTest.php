<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\Entity\GeneratedDto;
use App\Entity\SchemaDrift;
use App\Event\DriftDetectedEvent;
use App\Event\DtoGeneratedEvent;
use App\Event\HealthStatusChangedEvent;
use App\Event\ThresholdExceededEvent;
use App\EventSubscriber\MercureEventSubscriber;
use App\Service\Mercure\MercurePublisherServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(MercureEventSubscriber::class)]
#[AllowMockObjectsWithoutExpectations]
final class MercureEventSubscriberTest extends TestCase
{
    private MercurePublisherServiceInterface&MockObject $mercurePublisher;
    private MercureEventSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->mercurePublisher = $this->createMock(MercurePublisherServiceInterface::class);
        $this->subscriber = new MercureEventSubscriber($this->mercurePublisher);
    }

    #[Test]
    public function getSubscribedEventsReturnsAllEvents(): void
    {
        $events = MercureEventSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(DriftDetectedEvent::class, $events);
        self::assertArrayHasKey(HealthStatusChangedEvent::class, $events);
        self::assertArrayHasKey(ThresholdExceededEvent::class, $events);
        self::assertArrayHasKey(DtoGeneratedEvent::class, $events);
    }

    #[Test]
    public function onDriftDetectedPublishesDrift(): void
    {
        $drift = $this->createMock(SchemaDrift::class);
        $event = new DriftDetectedEvent($drift);

        $this->mercurePublisher->expects($this->once())
            ->method('publishDriftDetected')
            ->with($drift);

        $this->subscriber->onDriftDetected($event);
    }

    #[Test]
    public function onHealthStatusChangedPublishesStatusChange(): void
    {
        $event = new HealthStatusChangedEvent(
            host: 'api.example.com',
            oldStatus: 'healthy',
            newStatus: 'degraded',
        );

        $this->mercurePublisher->expects($this->once())
            ->method('publishHealthStatusChange')
            ->with('api.example.com', 'healthy', 'degraded');

        $this->subscriber->onHealthStatusChanged($event);
    }

    #[Test]
    public function onThresholdExceededPublishesThreshold(): void
    {
        $event = new ThresholdExceededEvent(
            host: 'api.example.com',
            metric: 'latency_p99',
            value: 1500.0,
            threshold: 1000.0,
        );

        $this->mercurePublisher->expects($this->once())
            ->method('publishRequestThresholdExceeded')
            ->with('api.example.com', 'latency_p99', 1500.0, 1000.0);

        $this->subscriber->onThresholdExceeded($event);
    }

    #[Test]
    public function onDtoGeneratedPublishesDto(): void
    {
        $dto = $this->createMock(GeneratedDto::class);
        $event = new DtoGeneratedEvent($dto);

        $this->mercurePublisher->expects($this->once())
            ->method('publishDtoGenerated')
            ->with($dto);

        $this->subscriber->onDtoGenerated($event);
    }
}
