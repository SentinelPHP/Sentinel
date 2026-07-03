<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SchemaLearnMessage;
use App\Repository\ApiTokenRepository;
use SentinelPHP\Redact\PiiRedactorInterface;
use App\Service\SchemaLearningServiceInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class SchemaLearnMessageHandler
{
    public function __construct(
        private SchemaLearningServiceInterface $schemaLearningService,
        private ApiTokenRepository $tokenRepository,
        private PiiRedactorInterface $piiRedactor,
    ) {
    }

    public function __invoke(SchemaLearnMessage $message): void
    {
        $token = $this->tokenRepository->find(Uuid::fromString($message->tokenId));

        if ($token === null) {
            return;
        }

        $redactedBody = $this->redactForSchemaLearning($message->responseBody, $token->getCustomRedactionPatterns());

        $this->schemaLearningService->learn(
            $token,
            $message->targetHost,
            $message->path,
            $message->method,
            $redactedBody,
        );
    }

    /**
     * Redact sensitive example values before schema generation.
     *
     * @param array<string, string>|null $customPatterns
     */
    private function redactForSchemaLearning(string $responseBody, ?array $customPatterns): string
    {
        if ($responseBody === '') {
            return $responseBody;
        }

        $redacted = $this->piiRedactor->redact($responseBody, null, $customPatterns);

        return is_string($redacted) ? $redacted : (string) json_encode($redacted);
    }
}
