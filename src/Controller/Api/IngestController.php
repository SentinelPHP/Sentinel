<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Security\RateLimiterInterface;
use App\Security\TokenAuthenticatorInterface;
use App\Storage\MessengerStorageFactory;
use SentinelPHP\Core\Record\ApiCallRecord;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * API endpoint for ingesting intercepted API calls from external clients
 * using the SentinelPHP Core package.
 */
#[Route('/api/ingest')]
final class IngestController
{
    public function __construct(
        private readonly TokenAuthenticatorInterface $tokenAuthenticator,
        private readonly MessengerStorageFactory $storageFactory,
        private readonly ?RateLimiterInterface $rateLimiter = null,
    ) {
    }

    #[Route('', name: 'api_ingest', methods: ['POST'])]
    public function ingest(Request $request): JsonResponse
    {
        $clientIp = $request->getClientIp() ?? 'unknown';

        $rateLimitError = $this->checkRateLimit($clientIp);
        if ($rateLimitError !== null) {
            return $rateLimitError;
        }

        $authResult = $this->tokenAuthenticator->authenticate($request);
        if (!$authResult->isAuthenticated || $authResult->token === null) {
            return new JsonResponse(
                ['error' => true, 'message' => $authResult->error ?? 'Unauthorized'],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $payload = $this->parsePayload($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $validationError = $this->validatePayload($payload);
        if ($validationError !== null) {
            return $validationError;
        }

        /** @var string $method */
        $method = $payload['method'];
        /** @var string $url */
        $url = $payload['url'];
        /** @var int|float|string $statusCode */
        $statusCode = $payload['statusCode'];
        /** @var int|float|string $latencyMs */
        $latencyMs = $payload['latencyMs'];
        /** @var string|null $requestBody */
        $requestBody = $payload['requestBody'] ?? null;
        /** @var string|null $responseBody */
        $responseBody = $payload['responseBody'] ?? null;
        /** @var array<string, string|list<string>> $requestHeaders */
        $requestHeaders = isset($payload['requestHeaders']) && is_array($payload['requestHeaders']) ? $payload['requestHeaders'] : [];
        /** @var array<string, string|list<string>> $responseHeaders */
        $responseHeaders = isset($payload['responseHeaders']) && is_array($payload['responseHeaders']) ? $payload['responseHeaders'] : [];
        /** @var string|null $id */
        $id = isset($payload['id']) && is_string($payload['id']) ? $payload['id'] : null;

        $record = new ApiCallRecord(
            method: $method,
            url: $url,
            statusCode: (int) $statusCode,
            latencyMs: (float) $latencyMs,
            timestamp: new \DateTimeImmutable(),
            requestHeaders: $requestHeaders,
            requestBody: $requestBody,
            responseHeaders: $responseHeaders,
            responseBody: $responseBody,
            id: $id,
        );

        $storage = $this->storageFactory->createForToken($authResult->token);
        $storage->store($record);

        return new JsonResponse(
            ['success' => true],
            Response::HTTP_ACCEPTED
        );
    }

    private function checkRateLimit(string $clientIp): ?JsonResponse
    {
        if ($this->rateLimiter === null) {
            return null;
        }

        $result = $this->rateLimiter->isAllowed($clientIp);
        if (!$result->isAllowed) {
            return new JsonResponse(
                ['error' => true, 'message' => 'Too many requests. Please try again later.'],
                Response::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => (string) ($result->retryAfterSeconds ?? 60)]
            );
        }

        return null;
    }

    /**
     * @return array<string, mixed>|JsonResponse
     */
    private function parsePayload(Request $request): array|JsonResponse
    {
        $content = $request->getContent();
        if ($content === '') {
            return new JsonResponse(
                ['error' => true, 'message' => 'Request body is required'],
                Response::HTTP_BAD_REQUEST
            );
        }

        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            return $payload;
        } catch (\JsonException $e) {
            return new JsonResponse(
                ['error' => true, 'message' => 'Invalid JSON: ' . $e->getMessage()],
                Response::HTTP_BAD_REQUEST
            );
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function validatePayload(array $payload): ?JsonResponse
    {
        $requiredFields = ['method', 'url', 'statusCode', 'latencyMs'];
        $missingFields = [];

        foreach ($requiredFields as $field) {
            if (!isset($payload[$field])) {
                $missingFields[] = $field;
            }
        }

        if ($missingFields !== []) {
            return new JsonResponse(
                ['error' => true, 'message' => 'Missing required fields: ' . implode(', ', $missingFields)],
                Response::HTTP_BAD_REQUEST
            );
        }

        if (!is_string($payload['method'])) {
            return new JsonResponse(
                ['error' => true, 'message' => 'Field "method" must be a string'],
                Response::HTTP_BAD_REQUEST
            );
        }

        if (!is_string($payload['url'])) {
            return new JsonResponse(
                ['error' => true, 'message' => 'Field "url" must be a string'],
                Response::HTTP_BAD_REQUEST
            );
        }

        if (!is_numeric($payload['statusCode'])) {
            return new JsonResponse(
                ['error' => true, 'message' => 'Field "statusCode" must be a number'],
                Response::HTTP_BAD_REQUEST
            );
        }

        if (!is_numeric($payload['latencyMs'])) {
            return new JsonResponse(
                ['error' => true, 'message' => 'Field "latencyMs" must be a number'],
                Response::HTTP_BAD_REQUEST
            );
        }

        return null;
    }
}
