<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\DriftPayload;
use App\Entity\RequestLog;
use App\Message\DriftPayloadMessage;
use App\Service\BodyCompressionServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class DriftPayloadMessageHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private BodyCompressionServiceInterface $compressionService,
    ) {
    }

    public function __invoke(DriftPayloadMessage $message): void
    {
        $requestLog = $this->entityManager->find(RequestLog::class, Uuid::fromString($message->requestLogId));

        if ($requestLog === null) {
            return;
        }

        $payload = new DriftPayload();
        $payload->setRequestLog($requestLog)
            ->setRequestBody($this->compressIfNotEmpty($message->requestBody))
            ->setResponseBody($this->compressIfNotEmpty($message->responseBody))
            ->setRequestHeaders($this->compressIfNotEmpty($message->requestHeaders))
            ->setResponseHeaders($this->compressIfNotEmpty($message->responseHeaders))
            ->setIsCompressed(true);

        $this->entityManager->persist($payload);
        $this->entityManager->flush();
    }

    private function compressIfNotEmpty(?string $data): ?string
    {
        if ($data === null || $data === '') {
            return $data;
        }

        return $this->compressionService->compress($data);
    }
}
