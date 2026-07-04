<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\RequestLog;
use App\Enum\DataProtectionStrategy;
use App\Enum\LogLevel;
use App\Message\RequestLogMessage;
use App\Repository\ApiTokenRepository;
use App\Repository\RequestLogRepository;
use App\Service\BodyCompressionServiceInterface;
use App\Service\Dashboard\LatencyMetricsServiceInterface;
use App\Service\DataProtection\DataProtectionServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class RequestLogMessageHandler
{
    private const array SENSITIVE_HEADER_NAMES = [
        'authorization',
        'cookie',
        'proxy-authorization',
        'set-cookie',
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ApiTokenRepository $tokenRepository,
        private RequestLogRepository $requestLogRepository,
        private DataProtectionServiceInterface $dataProtectionService,
        private BodyCompressionServiceInterface $compressionService,
        private LatencyMetricsServiceInterface $latencyMetricsService,
        private bool $compressAuditLogs = false,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function __invoke(RequestLogMessage $message): void
    {
        if ($message->logLevel->shouldSkipLogging()) {
            return;
        }

        $logId = Uuid::fromString($message->requestLogId);

        // Idempotency check: skip if this log was already persisted (e.g., on retry)
        if ($this->requestLogRepository->find($logId) !== null) {
            $this->logger?->debug('RequestLog already exists, skipping duplicate', [
                'requestLogId' => $message->requestLogId,
            ]);
            return;
        }

        $token = $message->tokenId !== null
            ? $this->tokenRepository->find(Uuid::fromString($message->tokenId))
            : null;

        $fields = $message->logLevel->getLoggedFields();

        $strategy = $this->dataProtectionService->getEffectiveStrategy($token);
        $customPatterns = $token?->getCustomRedactionPatterns();

        $requestHeaders = $fields['requestHeaders'] ? $message->requestHeaders : null;
        $requestBody = $fields['requestBody'] ? $message->requestBody : null;
        $responseHeaders = $fields['responseHeaders'] ? $message->responseHeaders : null;
        $responseBody = $fields['responseBody'] ? $message->responseBody : null;

        $requestHeaders = $this->redactSensitiveHeaders($requestHeaders);
        $responseHeaders = $this->redactSensitiveHeaders($responseHeaders);

        $isEncrypted = false;

        if ($strategy !== DataProtectionStrategy::None) {
            try {
                $requestHeaders = $requestHeaders !== null
                    ? $this->dataProtectionService->protect($requestHeaders, $strategy, $customPatterns)
                    : null;
                $requestBody = $requestBody !== null
                    ? $this->dataProtectionService->protect($requestBody, $strategy, $customPatterns)
                    : null;
                $responseHeaders = $responseHeaders !== null
                    ? $this->dataProtectionService->protect($responseHeaders, $strategy, $customPatterns)
                    : null;
                $responseBody = $responseBody !== null
                    ? $this->dataProtectionService->protect($responseBody, $strategy, $customPatterns)
                    : null;

                $isEncrypted = $strategy->shouldEncrypt();
            } catch (\Throwable $e) {
                $this->logger?->error('Data protection failed for request log', [
                    'requestLogId' => $message->requestLogId,
                    'strategy' => $strategy->value,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $isCompressed = false;
        if ($this->compressAuditLogs) {
            $requestHeaders = $this->compressIfNotNull($requestHeaders);
            $requestBody = $this->compressIfNotNull($requestBody);
            $responseHeaders = $this->compressIfNotNull($responseHeaders);
            $responseBody = $this->compressIfNotNull($responseBody);
            $isCompressed = true;
        }

        $log = new RequestLog($logId);
        $log->setToken($token)
            ->setTargetHost($message->targetHost)
            ->setRequestMethod($message->requestMethod)
            ->setRequestPath($message->requestPath)
            ->setResponseStatusCode($message->responseStatusCode)
            ->setLatencyMs($message->latencyMs)
            ->setRequestHeaders($requestHeaders)
            ->setRequestBody($requestBody)
            ->setResponseHeaders($responseHeaders)
            ->setResponseBody($responseBody)
            ->setIsEncrypted($isEncrypted)
            ->setIsCompressed($isCompressed);

        $this->entityManager->persist($log);
        $this->entityManager->flush();

        $this->latencyMetricsService->recordLatencySample($message->targetHost, $message->latencyMs);
    }

    private function compressIfNotNull(?string $data): ?string
    {
        if ($data === null || $data === '') {
            return $data;
        }

        return $this->compressionService->compress($data);
    }

    private function redactSensitiveHeaders(?string $headers): ?string
    {
        if ($headers === null || $headers === '') {
            return $headers;
        }

        try {
            $decoded = json_decode($headers, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($decoded)) {
                return $headers;
            }

            $didRedact = false;
            foreach ($decoded as $name => $value) {
                if (!is_string($name)) {
                    continue;
                }

                if (in_array(strtolower($name), self::SENSITIVE_HEADER_NAMES, true)) {
                    $decoded[$name] = '[REDACTED]';
                    $didRedact = true;
                }
            }

            if (!$didRedact) {
                return $headers;
            }

            return json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException) {
            return $headers;
        }
    }
}
