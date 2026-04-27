<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\RequestLog;
use App\Message\RequestLogMessage;
use App\Message\SchemaLearnMessage;
use App\Message\SchemaValidateMessage;
use App\MessageHandler\RequestLogMessageHandler;
use App\MessageHandler\SchemaLearnMessageHandler;
use App\Repository\ApiSchemaRepository;
use App\Repository\RequestLogRepository;
use App\Tests\Factories\ApiTokenFactory;
use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use SentinelPHP\Core\Config\InterceptorConfig;
use SentinelPHP\Core\Record\ApiCallRecord;
use SentinelPHP\Core\SentinelInterceptor;
use SentinelPHP\Core\Storage\SentinelHttpStorage;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * E2E Test: External Client → SentinelHttpStorage → /api/ingest → Message Handlers
 *
 * This test simulates an external application using the Core package's
 * SentinelHttpStorage to send API call records to the SentinelPHP server.
 * It verifies the complete flow from external client through message processing.
 */
final class ExternalClientIngestFlowTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private KernelBrowser $client;
    private string $plainToken = 'external-client-token';

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testExternalClientCanSendApiCallRecordToIngestEndpoint(): void
    {
        // Step 1: Create API token for external client
        $token = ApiTokenFactory::new()
            ->withKnownToken($this->plainToken)
            ->create([
                'name' => 'External App Token',
                'allowedTargets' => ['api.external.com'],
            ]);

        // Step 2: Create an ApiCallRecord as an external client would
        $record = new ApiCallRecord(
            method: 'GET',
            url: 'https://api.external.com/users/123',
            statusCode: 200,
            latencyMs: 45.5,
            timestamp: new DateTimeImmutable(),
            requestHeaders: ['Accept' => 'application/json'],
            requestBody: null,
            responseHeaders: ['Content-Type' => 'application/json'],
            responseBody: '{"id":123,"name":"John Doe","email":"john@example.com"}',
            id: \Symfony\Component\Uid\Uuid::v7()->toRfc4122(),
        );

        // Step 3: Convert to ingest payload (as SentinelHttpStorage would)
        $payload = $record->toArray();

        // Step 4: Send to /api/ingest endpoint
        $this->client->request('POST', '/api/ingest', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($payload, JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();

        /** @var array{success?: bool} $response */
        $response = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($response['success'] ?? false);

        // Step 5: Verify messages were dispatched
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $messages = $transport->getSent();

        // Should have RequestLogMessage, SchemaLearnMessage, SchemaValidateMessage
        $messageTypes = array_map(fn ($env) => $env->getMessage()::class, $messages);

        self::assertContains(RequestLogMessage::class, $messageTypes);
        self::assertContains(SchemaLearnMessage::class, $messageTypes);
        self::assertContains(SchemaValidateMessage::class, $messageTypes);
    }

    public function testExternalClientFlowCreatesRequestLog(): void
    {
        // Step 1: Create API token
        $token = ApiTokenFactory::new()
            ->withKnownToken($this->plainToken)
            ->create(['name' => 'External App']);

        // Step 2: Send ingest request (id must be a valid UUID)
        $recordId = \Symfony\Component\Uid\Uuid::v7()->toRfc4122();
        $record = new ApiCallRecord(
            method: 'POST',
            url: 'https://api.external.com/orders',
            statusCode: 201,
            latencyMs: 120.0,
            timestamp: new DateTimeImmutable(),
            requestBody: '{"product_id":42,"quantity":2}',
            responseBody: '{"order_id":"ORD-123","status":"created"}',
            id: $recordId,
        );

        $this->client->request('POST', '/api/ingest', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($record->toArray(), JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();

        // Step 3: Process the RequestLogMessage
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $messages = $transport->getSent();

        $requestLogMessage = null;
        foreach ($messages as $envelope) {
            if ($envelope->getMessage() instanceof RequestLogMessage) {
                $requestLogMessage = $envelope->getMessage();
                break;
            }
        }

        self::assertNotNull($requestLogMessage);
        self::assertSame('POST', $requestLogMessage->requestMethod);
        self::assertSame('/orders', $requestLogMessage->requestPath);
        self::assertSame(201, $requestLogMessage->responseStatusCode);
        self::assertSame(120, $requestLogMessage->latencyMs);

        // Step 4: Process the message through handler
        /** @var RequestLogMessageHandler $handler */
        $handler = self::getContainer()->get(RequestLogMessageHandler::class);
        $handler($requestLogMessage);

        // Step 5: Verify request log was persisted
        /** @var RequestLogRepository $repo */
        $repo = self::getContainer()->get(RequestLogRepository::class);
        $logs = $repo->findAll();

        self::assertCount(1, $logs);
        self::assertSame('POST', $logs[0]->getRequestMethod());
        self::assertSame('/orders', $logs[0]->getRequestPath());
    }

    public function testExternalClientFlowTriggersSchemaLearning(): void
    {
        // Step 1: Create API token in Learning mode
        $token = ApiTokenFactory::new()
            ->withKnownToken($this->plainToken)
            ->learning()
            ->create(['name' => 'External App']);

        // Step 2: Send ingest request with JSON response
        $record = new ApiCallRecord(
            method: 'GET',
            url: 'https://api.external.com/products/456',
            statusCode: 200,
            latencyMs: 30.0,
            timestamp: new DateTimeImmutable(),
            responseBody: '{"id":456,"name":"Widget","price":19.99,"in_stock":true}',
            id: \Symfony\Component\Uid\Uuid::v7()->toRfc4122(),
        );

        $this->client->request('POST', '/api/ingest', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($record->toArray(), JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();

        // Step 3: Get SchemaLearnMessage
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $messages = $transport->getSent();

        $schemaLearnMessage = null;
        foreach ($messages as $envelope) {
            if ($envelope->getMessage() instanceof SchemaLearnMessage) {
                $schemaLearnMessage = $envelope->getMessage();
                break;
            }
        }

        self::assertNotNull($schemaLearnMessage);
        self::assertSame('GET', $schemaLearnMessage->method);
        self::assertSame('api.external.com', $schemaLearnMessage->targetHost);
        self::assertSame('/products/456', $schemaLearnMessage->path);
        self::assertSame('{"id":456,"name":"Widget","price":19.99,"in_stock":true}', $schemaLearnMessage->responseBody);

        // Step 4: Verify the message has the correct token ID
        self::assertSame($token->getId()->toRfc4122(), $schemaLearnMessage->tokenId);

        // Step 5: Process the message through handler
        /** @var SchemaLearnMessageHandler $handler */
        $handler = self::getContainer()->get(SchemaLearnMessageHandler::class);
        $handler($schemaLearnMessage);

        // Step 6: Verify schema was learned
        /** @var ApiSchemaRepository $repo */
        $repo = self::getContainer()->get(ApiSchemaRepository::class);
        $schemas = $repo->findAll();

        self::assertCount(1, $schemas);
        self::assertSame('api.external.com', $schemas[0]->getTargetHost());
        self::assertSame('/products/456', $schemas[0]->getEndpointPath());
        self::assertSame('GET', $schemas[0]->getHttpMethod());

        // Verify schema structure was inferred
        $jsonSchema = $schemas[0]->getJsonSchema();
        self::assertArrayHasKey('properties', $jsonSchema);
        /** @var array<string, mixed> $properties */
        $properties = $jsonSchema['properties'];
        self::assertArrayHasKey('id', $properties);
        self::assertArrayHasKey('name', $properties);
        self::assertArrayHasKey('price', $properties);
    }

    public function testSentinelHttpStorageIntegration(): void
    {
        // Step 1: Create API token
        $token = ApiTokenFactory::new()
            ->withKnownToken($this->plainToken)
            ->create(['name' => 'External App']);

        // Step 2: Create a mock HTTP client that captures requests
        $capturedRequests = [];
        $mockHandler = new MockHandler([
            new Response(200, [], '{"success":true}'),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(function ($handler) use (&$capturedRequests) {
            return function ($request, $options) use ($handler, &$capturedRequests) {
                $capturedRequests[] = $request;
                return $handler($request, $options);
            };
        });

        $httpClient = new Client(['handler' => $handlerStack]);
        $httpFactory = new HttpFactory();

        // Step 3: Create SentinelHttpStorage pointing to our test server
        // Note: In real usage, this would point to the actual SentinelPHP server
        // baseUrl should NOT include /api/ingest - the storage appends it
        $storage = new SentinelHttpStorage(
            httpClient: $httpClient,
            requestFactory: $httpFactory,
            streamFactory: $httpFactory,
            baseUrl: 'http://localhost',
            apiToken: $this->plainToken,
        );

        // Step 4: Create interceptor with storage
        $interceptor = new SentinelInterceptor(
            storage: $storage,
            config: InterceptorConfig::minimal(),
        );

        // Step 5: Intercept an API call
        $record = $interceptor->intercept(
            method: 'GET',
            url: 'https://api.external.com/health',
            statusCode: 200,
            latencyMs: 5.0,
            responseBody: '{"status":"ok"}',
        );

        // Step 6: Verify the storage sent the request
        self::assertCount(1, $capturedRequests);

        /** @var \Psr\Http\Message\RequestInterface $sentRequest */
        $sentRequest = $capturedRequests[0];
        self::assertSame('POST', $sentRequest->getMethod());
        self::assertStringContainsString('/api/ingest', (string) $sentRequest->getUri());
        self::assertSame('Bearer ' . $this->plainToken, $sentRequest->getHeaderLine('Authorization'));
        self::assertSame('application/json', $sentRequest->getHeaderLine('Content-Type'));

        // Verify payload
        /** @var array{method?: string, url?: string, statusCode?: int} $payload */
        $payload = json_decode((string) $sentRequest->getBody(), true);
        self::assertSame('GET', $payload['method'] ?? null);
        self::assertSame('https://api.external.com/health', $payload['url'] ?? null);
        self::assertSame(200, $payload['statusCode'] ?? null);
    }

    public function testMultipleRecordsFromExternalClient(): void
    {
        // Step 1: Create API token
        $token = ApiTokenFactory::new()
            ->withKnownToken($this->plainToken)
            ->create(['name' => 'External App']);

        // Step 2: Send multiple ingest requests
        $records = [
            new ApiCallRecord(
                method: 'GET',
                url: 'https://api.external.com/users',
                statusCode: 200,
                latencyMs: 50.0,
                timestamp: new DateTimeImmutable(),
                responseBody: '[{"id":1},{"id":2}]',
            ),
            new ApiCallRecord(
                method: 'GET',
                url: 'https://api.external.com/users/1',
                statusCode: 200,
                latencyMs: 30.0,
                timestamp: new DateTimeImmutable(),
                responseBody: '{"id":1,"name":"Alice"}',
            ),
            new ApiCallRecord(
                method: 'POST',
                url: 'https://api.external.com/users',
                statusCode: 201,
                latencyMs: 100.0,
                timestamp: new DateTimeImmutable(),
                requestBody: '{"name":"Bob"}',
                responseBody: '{"id":3,"name":"Bob"}',
            ),
        ];

        // Collect all messages across requests
        $allMessages = [];

        foreach ($records as $record) {
            $this->client->request('POST', '/api/ingest', [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
                'CONTENT_TYPE' => 'application/json',
            ], json_encode($record->toArray(), JSON_THROW_ON_ERROR));

            self::assertResponseIsSuccessful();

            // Collect messages after each request
            /** @var InMemoryTransport $transport */
            $transport = self::getContainer()->get('messenger.transport.async');
            foreach ($transport->getSent() as $envelope) {
                $allMessages[] = $envelope;
            }
        }

        // Each record should dispatch 3 messages (log, learn, validate)
        $requestLogCount = 0;
        $schemaLearnCount = 0;

        foreach ($allMessages as $envelope) {
            if ($envelope->getMessage() instanceof RequestLogMessage) {
                $requestLogCount++;
            }
            if ($envelope->getMessage() instanceof SchemaLearnMessage) {
                $schemaLearnCount++;
            }
        }

        self::assertSame(3, $requestLogCount);
        self::assertSame(3, $schemaLearnCount);
    }
}
