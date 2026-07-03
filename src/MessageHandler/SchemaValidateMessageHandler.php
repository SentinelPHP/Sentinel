<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SchemaValidateMessage;
use App\Repository\ApiTokenRepository;
use App\Service\SchemaValidationServiceInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class SchemaValidateMessageHandler
{
    public function __construct(
        private SchemaValidationServiceInterface $schemaValidationService,
        private ApiTokenRepository $tokenRepository,
    ) {
    }

    public function __invoke(SchemaValidateMessage $message): void
    {
        $token = $this->tokenRepository->find(Uuid::fromString($message->tokenId));

        if ($token === null) {
            return;
        }

        $this->schemaValidationService->validate(
            $token,
            $message->targetHost,
            $message->path,
            $message->method,
            $message->responseBody,
            $message->requestBody,
            $message->requestLogId,
            $message->requestHeaders,
            $message->responseHeaders,
        );
    }
}
