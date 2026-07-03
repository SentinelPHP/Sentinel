<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mercure;

use App\Entity\ApiSchema;
use App\Entity\ApiToken;
use App\Entity\GeneratedDto;
use App\Entity\SchemaDrift;
use App\Enum\DtoGenerationStatus;
use App\Service\Mercure\MercurePublisherService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use SentinelPHP\Drift\Enum\DriftSeverity;
use SentinelPHP\Drift\Enum\DriftType;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

#[CoversClass(MercurePublisherService::class)]
#[AllowMockObjectsWithoutExpectations]
final class MercurePublisherServiceTest extends TestCase
{
    private HubInterface&MockObject $hub;
    private LoggerInterface&MockObject $logger;
    private MercurePublisherService $service;

    protected function setUp(): void
    {
        $this->hub = $this->createMock(HubInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new MercurePublisherService($this->hub, $this->logger);
    }

    private function createDrift(): SchemaDrift
    {
        $token = $this->createMock(ApiToken::class);
        $token->method('getId')->willReturn(Uuid::v4());
        $token->method('getName')->willReturn('Test Token');

        $schema = $this->createMock(ApiSchema::class);
        $schema->method('getEndpointPath')->willReturn('/users');
        $schema->method('getHttpMethod')->willReturn('GET');
        $schema->method('getTargetHost')->willReturn('api.example.com');
        $schema->method('getToken')->willReturn($token);

        $drift = $this->createMock(SchemaDrift::class);
        $drift->method('getId')->willReturn(Uuid::v4());
        $drift->method('getSeverity')->willReturn(DriftSeverity::Warning);
        $drift->method('getDriftType')->willReturn(DriftType::FieldAdded);
        $drift->method('getPath')->willReturn('properties.newField');
        $drift->method('getSchema')->willReturn($schema);
        $drift->method('getToken')->willReturn($token);
        $drift->method('getCreatedAt')->willReturn(new \DateTimeImmutable());

        return $drift;
    }

    private function createGeneratedDto(): GeneratedDto
    {
        $token = $this->createMock(ApiToken::class);
        $token->method('getId')->willReturn(Uuid::v4());
        $token->method('getName')->willReturn('Test Token');

        $schema = $this->createMock(ApiSchema::class);
        $schema->method('getId')->willReturn(Uuid::v4());
        $schema->method('getEndpointPath')->willReturn('/users');
        $schema->method('getHttpMethod')->willReturn('GET');
        $schema->method('getTargetHost')->willReturn('api.example.com');
        $schema->method('getToken')->willReturn($token);

        $dto = $this->createMock(GeneratedDto::class);
        $dto->method('getId')->willReturn(Uuid::v4());
        $dto->method('getClassName')->willReturn('GetUsersResponse');
        $dto->method('getNamespace')->willReturn('App\\Dto\\Generated');
        $dto->method('getVersion')->willReturn(1);
        $dto->method('getStatus')->willReturn(DtoGenerationStatus::Completed);
        $dto->method('getSchema')->willReturn($schema);
        $dto->method('getCreatedAt')->willReturn(new \DateTimeImmutable());

        return $dto;
    }

    #[Test]
    public function publishDriftDetectedPublishesToCorrectTopic(): void
    {
        $drift = $this->createDrift();

        $this->hub->expects($this->once())
            ->method('publish')
            ->with($this->callback(function (Update $update): bool {
                return $update->getTopics() === [MercurePublisherService::TOPIC_DRIFT];
            }));

        $this->service->publishDriftDetected($drift);
    }

    #[Test]
    public function publishDriftDetectedIncludesCorrectData(): void
    {
        $drift = $this->createDrift();

        $this->hub->expects($this->once())
            ->method('publish')
            ->with($this->callback(function (Update $update): bool {
                $data = json_decode($update->getData(), true);
                \assert(\is_array($data));

                return $data['type'] === 'drift_detected'
                    && isset($data['id'])
                    && $data['severity'] === 'warning'
                    && $data['driftType'] === 'field_added'
                    && $data['path'] === 'properties.newField'
                    && $data['endpoint'] === '/users'
                    && $data['method'] === 'GET'
                    && $data['host'] === 'api.example.com';
            }));

        $this->service->publishDriftDetected($drift);
    }

    #[Test]
    public function publishHealthStatusChangePublishesToCorrectTopic(): void
    {
        $this->hub->expects($this->once())
            ->method('publish')
            ->with($this->callback(function (Update $update): bool {
                return $update->getTopics() === [MercurePublisherService::TOPIC_HEALTH];
            }));

        $this->service->publishHealthStatusChange('api.example.com', 'healthy', 'degraded');
    }

    #[Test]
    public function publishHealthStatusChangeIncludesCorrectData(): void
    {
        $this->hub->expects($this->once())
            ->method('publish')
            ->with($this->callback(function (Update $update): bool {
                $data = json_decode($update->getData(), true);
                \assert(\is_array($data));

                return $data['type'] === 'health_status_change'
                    && $data['host'] === 'api.example.com'
                    && $data['oldStatus'] === 'healthy'
                    && $data['newStatus'] === 'degraded'
                    && isset($data['timestamp']);
            }));

        $this->service->publishHealthStatusChange('api.example.com', 'healthy', 'degraded');
    }

    #[Test]
    public function publishRequestThresholdExceededPublishesToCorrectTopic(): void
    {
        $this->hub->expects($this->once())
            ->method('publish')
            ->with($this->callback(function (Update $update): bool {
                return $update->getTopics() === [MercurePublisherService::TOPIC_THRESHOLD];
            }));

        $this->service->publishRequestThresholdExceeded('api.example.com', 'latency_p99', 1500.0, 1000.0);
    }

    #[Test]
    public function publishRequestThresholdExceededIncludesCorrectData(): void
    {
        $this->hub->expects($this->once())
            ->method('publish')
            ->with($this->callback(function (Update $update): bool {
                $data = json_decode($update->getData(), true);
                \assert(\is_array($data));

                return $data['type'] === 'threshold_exceeded'
                    && $data['host'] === 'api.example.com'
                    && $data['metric'] === 'latency_p99'
                    && $data['value'] === 1500
                    && $data['threshold'] === 1000;
            }));

        $this->service->publishRequestThresholdExceeded('api.example.com', 'latency_p99', 1500.0, 1000.0);
    }

    #[Test]
    public function publishDtoGeneratedPublishesToCorrectTopic(): void
    {
        $dto = $this->createGeneratedDto();

        $this->hub->expects($this->once())
            ->method('publish')
            ->with($this->callback(function (Update $update): bool {
                return $update->getTopics() === [MercurePublisherService::TOPIC_DTO];
            }));

        $this->service->publishDtoGenerated($dto);
    }

    #[Test]
    public function publishDtoGeneratedIncludesCorrectData(): void
    {
        $dto = $this->createGeneratedDto();

        $this->hub->expects($this->once())
            ->method('publish')
            ->with($this->callback(function (Update $update): bool {
                $data = json_decode($update->getData(), true);
                \assert(\is_array($data));

                return $data['type'] === 'dto_generated'
                    && $data['className'] === 'GetUsersResponse'
                    && $data['namespace'] === 'App\\Dto\\Generated'
                    && $data['version'] === 1;
            }));

        $this->service->publishDtoGenerated($dto);
    }

    #[Test]
    public function isAvailableReturnsTrueWhenHubHasPublicUrl(): void
    {
        $this->hub->method('getPublicUrl')->willReturn('https://mercure.example.com/.well-known/mercure');

        self::assertTrue($this->service->isAvailable());
    }

    #[Test]
    public function isAvailableReturnsFalseWhenHubHasEmptyUrl(): void
    {
        $this->hub->method('getPublicUrl')->willReturn('');

        self::assertFalse($this->service->isAvailable());
    }

    #[Test]
    public function isAvailableReturnsFalseWhenHubThrows(): void
    {
        $this->hub->method('getPublicUrl')->willThrowException(new \RuntimeException('Hub not configured'));

        self::assertFalse($this->service->isAvailable());
    }

    #[Test]
    public function publishLogsWarningOnFailure(): void
    {
        $this->hub->method('publish')->willThrowException(new \RuntimeException('Connection failed'));

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Failed to publish Mercure update',
                $this->callback(function (array $context): bool {
                    return isset($context['topic']) && isset($context['error']);
                })
            );

        $this->service->publishHealthStatusChange('api.example.com', 'healthy', 'degraded');
    }

    #[Test]
    public function publishLogsDebugOnSuccess(): void
    {
        $this->hub->method('publish')->willReturn('message-id');

        $this->logger->expects($this->once())
            ->method('debug')
            ->with(
                'Mercure update published',
                $this->callback(function (array $context): bool {
                    return $context['topic'] === MercurePublisherService::TOPIC_HEALTH
                        && $context['type'] === 'health_status_change';
                })
            );

        $this->service->publishHealthStatusChange('api.example.com', 'healthy', 'degraded');
    }
}
