<?php

declare(strict_types=1);

namespace App\Service\Mercure;

use App\Entity\GeneratedDto;
use App\Entity\SchemaDrift;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final readonly class MercurePublisherService implements MercurePublisherServiceInterface
{
    public const TOPIC_DRIFT = 'sentinel/drift';
    public const TOPIC_HEALTH = 'sentinel/health';
    public const TOPIC_THRESHOLD = 'sentinel/threshold';
    public const TOPIC_DTO = 'sentinel/dto';

    public function __construct(
        private HubInterface $hub,
        private LoggerInterface $logger,
    ) {
    }

    public function publishDriftDetected(SchemaDrift $drift): void
    {
        $schema = $drift->getSchema();
        $token = $drift->getToken();

        $data = [
            'type' => 'drift_detected',
            'id' => $drift->getId()->toRfc4122(),
            'severity' => $drift->getSeverity()->value,
            'driftType' => $drift->getDriftType()->value,
            'path' => $drift->getPath(),
            'endpoint' => $schema->getEndpointPath(),
            'method' => $schema->getHttpMethod(),
            'host' => $schema->getTargetHost(),
            'tokenId' => $token->getId()->toRfc4122(),
            'tokenName' => $token->getName(),
            'createdAt' => $drift->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];

        $this->publish(self::TOPIC_DRIFT, $data);
    }

    public function publishHealthStatusChange(string $host, string $oldStatus, string $newStatus): void
    {
        $data = [
            'type' => 'health_status_change',
            'host' => $host,
            'oldStatus' => $oldStatus,
            'newStatus' => $newStatus,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        $this->publish(self::TOPIC_HEALTH, $data);
    }

    public function publishRequestThresholdExceeded(string $host, string $metric, float $value, float $threshold): void
    {
        $data = [
            'type' => 'threshold_exceeded',
            'host' => $host,
            'metric' => $metric,
            'value' => $value,
            'threshold' => $threshold,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        $this->publish(self::TOPIC_THRESHOLD, $data);
    }

    public function publishDtoGenerated(GeneratedDto $dto): void
    {
        $schema = $dto->getSchema();
        $token = $schema->getToken();

        $data = [
            'type' => 'dto_generated',
            'id' => $dto->getId()->toRfc4122(),
            'className' => $dto->getClassName(),
            'namespace' => $dto->getNamespace(),
            'version' => $dto->getVersion(),
            'status' => $dto->getStatus()->value,
            'schemaId' => $schema->getId()->toRfc4122(),
            'endpoint' => $schema->getEndpointPath(),
            'method' => $schema->getHttpMethod(),
            'host' => $schema->getTargetHost(),
            'tokenId' => $token->getId()->toRfc4122(),
            'tokenName' => $token->getName(),
            'createdAt' => $dto->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];

        $this->publish(self::TOPIC_DTO, $data);
    }

    public function isAvailable(): bool
    {
        try {
            return $this->hub->getPublicUrl() !== '';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function publish(string $topic, array $data): void
    {
        try {
            $update = new Update(
                $topic,
                json_encode($data, JSON_THROW_ON_ERROR),
            );

            $this->hub->publish($update);

            $this->logger->debug('Mercure update published', [
                'topic' => $topic,
                'type' => $data['type'] ?? 'unknown',
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to publish Mercure update', [
                'topic' => $topic,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
