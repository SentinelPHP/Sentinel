<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\RequestLog;
use App\Enum\DataProtectionStrategy;
use App\Enum\LogLevel;
use App\Message\RequestLogMessage;
use App\MessageHandler\RequestLogMessageHandler;
use App\Repository\RequestLogRepository;
use App\Service\DataProtection\DataEncryptionService;
use App\Service\DataProtection\DataProtectionService;
use App\Service\DataProtection\DataProtectionServiceInterface;
use App\Tests\Factories\ApiTokenFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

#[CoversClass(DataProtectionService::class)]
#[CoversClass(RequestLogMessageHandler::class)]
final class DataProtectionIntegrationTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private RequestLogMessageHandler $handler;
    private RequestLogRepository $requestLogRepository;
    private DataProtectionServiceInterface $dataProtectionService;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->handler = self::getContainer()->get(RequestLogMessageHandler::class);
        $this->requestLogRepository = self::getContainer()->get(RequestLogRepository::class);
        $this->dataProtectionService = self::getContainer()->get(DataProtectionServiceInterface::class);
    }

    #[Test]
    public function itStoresDataUnmodifiedWithStrategyNone(): void
    {
        $token = ApiTokenFactory::new()
            ->create(['dataProtectionStrategy' => DataProtectionStrategy::None]);

        $requestBody = '{"email": "john@example.com", "password": "secret123"}';
        $responseBody = '{"id": 1, "email": "john@example.com"}';

        $message = $this->createMessage(
            tokenId: $token->getId()->toRfc4122(),
            requestBody: $requestBody,
            responseBody: $responseBody,
        );

        ($this->handler)($message);

        $log = $this->getLastRequestLog();

        self::assertSame($requestBody, $log->getRequestBody());
        self::assertSame($responseBody, $log->getResponseBody());
        self::assertFalse($log->isEncrypted());
    }

    #[Test]
    public function itRedactsPiiWithStrategyRedact(): void
    {
        $token = ApiTokenFactory::new()
            ->create(['dataProtectionStrategy' => DataProtectionStrategy::Redact]);

        $requestBody = '{"email": "john@example.com", "card": "4111111111111111"}';
        $responseBody = '{"phone": "+1-555-123-4567", "ssn": "123-45-6789"}';

        $message = $this->createMessage(
            tokenId: $token->getId()->toRfc4122(),
            requestBody: $requestBody,
            responseBody: $responseBody,
        );

        ($this->handler)($message);

        $log = $this->getLastRequestLog();

        $storedRequest = $log->getRequestBody();
        $storedResponse = $log->getResponseBody();
        self::assertNotNull($storedRequest);
        self::assertNotNull($storedResponse);

        // Email should be redacted
        self::assertStringNotContainsString('john@example.com', $storedRequest);
        self::assertStringContainsString('@example.com', $storedRequest); // Domain preserved

        // Credit card should be redacted
        self::assertStringNotContainsString('4111111111111111', $storedRequest);
        self::assertStringContainsString('1111', $storedRequest); // Last 4 preserved

        // Phone should be redacted
        self::assertStringNotContainsString('+1-555-123-4567', $storedResponse);

        // SSN should be redacted - note: SSN pattern uses regex capture group for last 4
        self::assertStringNotContainsString('123-45-6789', $storedResponse);

        self::assertFalse($log->isEncrypted());
    }

    #[Test]
    public function itEncryptsDataWithStrategyEncrypt(): void
    {
        $token = ApiTokenFactory::new()
            ->create(['dataProtectionStrategy' => DataProtectionStrategy::Encrypt]);

        $requestBody = '{"email": "john@example.com", "password": "secret123"}';
        $responseBody = '{"id": 1, "status": "success"}';

        $message = $this->createMessage(
            tokenId: $token->getId()->toRfc4122(),
            requestBody: $requestBody,
            responseBody: $responseBody,
        );

        ($this->handler)($message);

        $log = $this->getLastRequestLog();

        $storedRequest = $log->getRequestBody();
        $storedResponse = $log->getResponseBody();
        self::assertNotNull($storedRequest);
        self::assertNotNull($storedResponse);

        // Data should be encrypted (base64 encoded ciphertext)
        self::assertNotSame($requestBody, $storedRequest);
        self::assertNotSame($responseBody, $storedResponse);
        self::assertStringNotContainsString('john@example.com', $storedRequest);
        self::assertStringNotContainsString('success', $storedResponse);

        // Verify it's valid base64
        self::assertNotFalse(base64_decode($storedRequest, true));
        self::assertNotFalse(base64_decode($storedResponse, true));

        self::assertTrue($log->isEncrypted());

        // Verify we can decrypt and get original data back
        $decryptedRequest = $this->dataProtectionService->retrieve($storedRequest, true);
        $decryptedResponse = $this->dataProtectionService->retrieve($storedResponse, true);

        self::assertSame($requestBody, $decryptedRequest);
        self::assertSame($responseBody, $decryptedResponse);
    }

    #[Test]
    public function itRedactsAndEncryptsWithStrategyRedactEncrypt(): void
    {
        $token = ApiTokenFactory::new()
            ->create(['dataProtectionStrategy' => DataProtectionStrategy::RedactEncrypt]);

        $requestBody = '{"email": "john@example.com", "card": "4111111111111111"}';
        $responseBody = '{"id": 1, "status": "success"}';

        $message = $this->createMessage(
            tokenId: $token->getId()->toRfc4122(),
            requestBody: $requestBody,
            responseBody: $responseBody,
        );

        ($this->handler)($message);

        $log = $this->getLastRequestLog();

        $storedRequest = $log->getRequestBody();
        self::assertNotNull($storedRequest);

        // Data should be encrypted
        self::assertNotSame($requestBody, $storedRequest);
        self::assertTrue($log->isEncrypted());

        // Decrypt and verify redaction was applied
        $decryptedRequest = $this->dataProtectionService->retrieve($storedRequest, true);

        // Email should be redacted (redaction happens before encryption)
        self::assertStringNotContainsString('john@example.com', $decryptedRequest);
        self::assertStringContainsString('@example.com', $decryptedRequest);

        // Credit card should be redacted
        self::assertStringNotContainsString('4111111111111111', $decryptedRequest);
        self::assertStringContainsString('1111', $decryptedRequest);
    }

    #[Test]
    public function itUsesTokenStrategyOverGlobalDefault(): void
    {
        // Token with explicit redact strategy
        $tokenWithRedact = ApiTokenFactory::new()
            ->create(['dataProtectionStrategy' => DataProtectionStrategy::Redact]);

        $requestBody = '{"email": "john@example.com"}';

        // Process with redact token
        $message1 = $this->createMessage(
            tokenId: $tokenWithRedact->getId()->toRfc4122(),
            requestBody: $requestBody,
        );
        ($this->handler)($message1);
        $log1 = $this->getLastRequestLog();

        $storedRequest = $log1->getRequestBody();
        self::assertNotNull($storedRequest);

        // Email should be redacted (token has explicit Redact strategy)
        self::assertStringNotContainsString('john@example.com', $storedRequest);
        self::assertStringContainsString('@example.com', $storedRequest);
    }

    #[Test]
    public function itUsesGlobalDefaultWhenTokenHasNoStrategy(): void
    {
        // Token with no strategy (should use global default which is 'none' in test env)
        $tokenWithDefault = ApiTokenFactory::new()->create();

        // Verify the token has no strategy set
        self::assertNull($tokenWithDefault->getDataProtectionStrategy());

        $requestBody = '{"email": "john@example.com"}';

        $message = $this->createMessage(
            tokenId: $tokenWithDefault->getId()->toRfc4122(),
            requestBody: $requestBody,
        );
        ($this->handler)($message);
        $log = $this->getLastRequestLog();

        // With null token strategy, global default (none) should apply
        // Email should NOT be redacted
        self::assertSame($requestBody, $log->getRequestBody());
    }

    #[Test]
    public function itAppliesCustomRedactionPatterns(): void
    {
        $customPatterns = [
            '/SECRET-[A-Z0-9]+/' => '[CUSTOM_REDACTED]',
            '/internal-id-\d+/' => '[ID_REDACTED]',
        ];

        $token = ApiTokenFactory::new()
            ->create([
                'dataProtectionStrategy' => DataProtectionStrategy::Redact,
                'customRedactionPatterns' => $customPatterns,
            ]);

        $requestBody = '{"apiKey": "SECRET-ABC123", "ref": "internal-id-42"}';

        $message = $this->createMessage(
            tokenId: $token->getId()->toRfc4122(),
            requestBody: $requestBody,
        );

        ($this->handler)($message);

        $log = $this->getLastRequestLog();

        $storedRequest = $log->getRequestBody();
        self::assertNotNull($storedRequest);

        // Custom patterns are applied
        self::assertStringContainsString('[ID_REDACTED]', $storedRequest);
        self::assertStringNotContainsString('internal-id-42', $storedRequest);
        // Note: SECRET-ABC123 may also match the default API key pattern
        self::assertStringNotContainsString('SECRET-ABC123', $storedRequest);
    }

    #[Test]
    public function itHandlesFullRoundTripStorageAndRetrieval(): void
    {
        $token = ApiTokenFactory::new()
            ->create(['dataProtectionStrategy' => DataProtectionStrategy::RedactEncrypt]);

        $originalRequest = (string) json_encode([
            'user' => [
                'email' => 'sensitive@example.com',
                'phone' => '+1-555-987-6543',
                'creditCard' => '4532015112830366',
            ],
            'action' => 'purchase',
        ]);

        $originalResponse = (string) json_encode([
            'orderId' => 'ORD-12345',
            'status' => 'confirmed',
            'total' => 99.99,
        ]);

        $message = $this->createMessage(
            tokenId: $token->getId()->toRfc4122(),
            requestBody: $originalRequest,
            responseBody: $originalResponse,
        );

        ($this->handler)($message);

        $log = $this->getLastRequestLog();
        $storedRequest = $log->getRequestBody();
        $storedResponse = $log->getResponseBody();
        self::assertNotNull($storedRequest);
        self::assertNotNull($storedResponse);

        // Verify storage state
        self::assertTrue($log->isEncrypted());
        self::assertStringNotContainsString('sensitive@example.com', $storedRequest);
        self::assertStringNotContainsString('ORD-12345', $storedResponse);

        // Retrieve and decrypt
        $retrievedRequest = $this->dataProtectionService->retrieve(
            $storedRequest,
            $log->isEncrypted()
        );
        $retrievedResponse = $this->dataProtectionService->retrieve(
            $storedResponse,
            $log->isEncrypted()
        );

        // Verify redaction is permanent (cannot recover original PII)
        self::assertStringNotContainsString('sensitive@example.com', $retrievedRequest);
        self::assertStringNotContainsString('+1-555-987-6543', $retrievedRequest);
        self::assertStringNotContainsString('4532015112830366', $retrievedRequest);

        // But structure and non-sensitive data should be intact
        self::assertStringContainsString('action', $retrievedRequest);
        self::assertStringContainsString('purchase', $retrievedRequest);
        self::assertStringContainsString('ORD-12345', $retrievedResponse);
        self::assertStringContainsString('confirmed', $retrievedResponse);
    }

    #[Test]
    public function itHandlesEmptyBodiesGracefully(): void
    {
        $token = ApiTokenFactory::new()
            ->create(['dataProtectionStrategy' => DataProtectionStrategy::RedactEncrypt]);

        $message = $this->createMessage(
            tokenId: $token->getId()->toRfc4122(),
            requestBody: '',
            responseBody: null,
        );

        ($this->handler)($message);

        $log = $this->getLastRequestLog();

        // Empty string is returned as-is, null stays null
        self::assertSame('', $log->getRequestBody());
        self::assertNull($log->getResponseBody());
        // isEncrypted reflects whether encryption was applied to non-empty data
        // With empty/null bodies, the flag depends on the strategy being applied
        // The handler sets isEncrypted based on strategy.shouldEncrypt() when strategy != None
        self::assertTrue($log->isEncrypted()); // Strategy is RedactEncrypt, so flag is set
    }

    #[Test]
    public function itProtectsAllFieldsIncludingHeaders(): void
    {
        $token = ApiTokenFactory::new()
            ->create(['dataProtectionStrategy' => DataProtectionStrategy::Redact]);

        $requestHeaders = '{"Authorization": "Bearer sk_live_abc123xyz", "X-Api-Key": "secret-key"}';
        $responseHeaders = '{"X-Request-Id": "req-123"}';

        $message = $this->createMessage(
            tokenId: $token->getId()->toRfc4122(),
            requestHeaders: $requestHeaders,
            responseHeaders: $responseHeaders,
            requestBody: '{"data": "test"}',
            responseBody: '{"result": "ok"}',
        );

        ($this->handler)($message);

        $log = $this->getLastRequestLog();

        $storedHeaders = $log->getRequestHeaders();
        self::assertNotNull($storedHeaders);

        // Bearer token should be redacted
        self::assertStringNotContainsString('sk_live_abc123xyz', $storedHeaders);
        self::assertStringContainsString('[REDACTED]', $storedHeaders);
    }

    #[Test]
    public function itRetrievesUnencryptedDataWithoutDecryption(): void
    {
        $token = ApiTokenFactory::new()
            ->create(['dataProtectionStrategy' => DataProtectionStrategy::Redact]);

        $requestBody = '{"email": "john@example.com"}';

        $message = $this->createMessage(
            tokenId: $token->getId()->toRfc4122(),
            requestBody: $requestBody,
        );

        ($this->handler)($message);

        $log = $this->getLastRequestLog();

        // Data is redacted but not encrypted
        self::assertFalse($log->isEncrypted());

        $storedRequest = $log->getRequestBody();
        self::assertNotNull($storedRequest);

        // Retrieve should return data as-is (no decryption needed)
        $retrieved = $this->dataProtectionService->retrieve(
            $storedRequest,
            $log->isEncrypted()
        );

        self::assertSame($log->getRequestBody(), $retrieved);
    }

    private function createMessage(
        ?string $tokenId = null,
        string $targetHost = 'api.example.com',
        string $requestMethod = 'POST',
        string $requestPath = '/users',
        int $responseStatusCode = 200,
        int $latencyMs = 100,
        ?string $requestHeaders = '{"Content-Type": "application/json"}',
        ?string $requestBody = null,
        ?string $responseHeaders = '{"X-Request-Id": "test-123"}',
        ?string $responseBody = null,
    ): RequestLogMessage {
        return new RequestLogMessage(
            requestLogId: Uuid::v7()->toRfc4122(),
            tokenId: $tokenId,
            targetHost: $targetHost,
            requestMethod: $requestMethod,
            requestPath: $requestPath,
            responseStatusCode: $responseStatusCode,
            latencyMs: $latencyMs,
            logLevel: LogLevel::FullAudit,
            requestHeaders: $requestHeaders,
            requestBody: $requestBody,
            responseHeaders: $responseHeaders,
            responseBody: $responseBody,
        );
    }

    private function getLastRequestLog(): RequestLog
    {
        $logs = $this->requestLogRepository->findAll();
        self::assertNotEmpty($logs, 'Expected at least one request log');

        return $logs[count($logs) - 1];
    }
}
