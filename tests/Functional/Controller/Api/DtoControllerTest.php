<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Entity\ApiToken;
use App\Tests\Factories\ApiSchemaFactory;
use App\Tests\Factories\ApiTokenFactory;
use App\Tests\Factories\GeneratedDtoFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class DtoControllerTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private KernelBrowser $client;
    private string $plainToken = 'test-api-token-12345';
    private ApiToken $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->token = ApiTokenFactory::new()
            ->withKnownToken($this->plainToken)
            ->create();
    }

    // ==================== AUTHENTICATION TESTS ====================

    public function testListRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/dtos');

        self::assertResponseStatusCodeSame(401);
        $this->assertErrorResponse('Missing or invalid Authorization header');
    }

    public function testListRejectsInvalidToken(): void
    {
        $this->client->request('GET', '/api/dtos', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer invalid-token',
        ]);

        self::assertResponseStatusCodeSame(401);
        $this->assertErrorResponse('Invalid API token');
    }

    public function testListRejectsInactiveToken(): void
    {
        $inactiveToken = ApiTokenFactory::new()
            ->withKnownToken('inactive-token')
            ->inactive()
            ->create();

        $this->client->request('GET', '/api/dtos', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer inactive-token',
        ]);

        self::assertResponseStatusCodeSame(401);
        $this->assertErrorResponse('Invalid API token');
    }

    // ==================== LIST ENDPOINT TESTS ====================

    public function testListReturnsEmptyArrayWhenNoDtos(): void
    {
        $this->client->request('GET', '/api/dtos', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
        ]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $items = $this->getDataArray();
        $meta = $this->getMetaArray();
        self::assertSame([], $items);
        self::assertSame(0, $meta['total']);
    }

    public function testListReturnsDtosForAuthenticatedToken(): void
    {
        $schema = ApiSchemaFactory::createOne(['token' => $this->token]);
        $dto = GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'className' => 'GetUsersResponse',
            'namespace' => 'App\\Dto\\Generated',
        ]);

        $this->client->request('GET', '/api/dtos', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
        ]);

        self::assertResponseIsSuccessful();

        $items = $this->getDataArray();
        $meta = $this->getMetaArray();
        self::assertCount(1, $items);
        self::assertSame('GetUsersResponse', $items[0]['className']);
        self::assertSame('App\\Dto\\Generated', $items[0]['namespace']);
        self::assertSame(1, $meta['total']);
    }

    public function testListDoesNotReturnDtosFromOtherTokens(): void
    {
        $otherToken = ApiTokenFactory::createOne();
        $otherSchema = ApiSchemaFactory::createOne(['token' => $otherToken]);
        GeneratedDtoFactory::createOne([
            'schema' => $otherSchema,
            'className' => 'OtherTokenDto',
        ]);

        $mySchema = ApiSchemaFactory::createOne(['token' => $this->token]);
        GeneratedDtoFactory::createOne([
            'schema' => $mySchema,
            'className' => 'MyTokenDto',
        ]);

        $this->client->request('GET', '/api/dtos', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
        ]);

        self::assertResponseIsSuccessful();

        $items = $this->getDataArray();
        self::assertCount(1, $items);
        self::assertSame('MyTokenDto', $items[0]['className']);
    }

    public function testListSupportsPagination(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $schema = ApiSchemaFactory::createOne(['token' => $this->token]);
            GeneratedDtoFactory::createOne([
                'schema' => $schema,
                'className' => "Dto{$i}",
            ]);
        }

        $this->client->request('GET', '/api/dtos?limit=2&offset=0', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
        ]);

        self::assertResponseIsSuccessful();

        $items = $this->getDataArray();
        $meta = $this->getMetaArray();
        self::assertCount(2, $items);
        self::assertSame(5, $meta['total']);
        self::assertSame(2, $meta['limit']);
        self::assertSame(0, $meta['offset']);
        self::assertTrue($meta['hasMore']);
    }

    public function testListSupportsClassNameFilter(): void
    {
        $schema1 = ApiSchemaFactory::createOne(['token' => $this->token]);
        $schema2 = ApiSchemaFactory::createOne(['token' => $this->token]);
        $schema3 = ApiSchemaFactory::createOne(['token' => $this->token]);
        GeneratedDtoFactory::createOne(['schema' => $schema1, 'className' => 'GetUsersResponse']);
        GeneratedDtoFactory::createOne(['schema' => $schema2, 'className' => 'PostUsersRequest']);
        GeneratedDtoFactory::createOne(['schema' => $schema3, 'className' => 'GetOrdersResponse']);

        $this->client->request('GET', '/api/dtos?class_name=Users', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
        ]);

        self::assertResponseIsSuccessful();

        $items = $this->getDataArray();
        self::assertCount(2, $items);
    }

    public function testListSupportsNamespaceFilter(): void
    {
        $schema1 = ApiSchemaFactory::createOne(['token' => $this->token]);
        $schema2 = ApiSchemaFactory::createOne(['token' => $this->token]);
        GeneratedDtoFactory::createOne([
            'schema' => $schema1,
            'className' => 'Dto1',
            'namespace' => 'App\\Dto\\V1',
        ]);
        GeneratedDtoFactory::createOne([
            'schema' => $schema2,
            'className' => 'Dto2',
            'namespace' => 'App\\Dto\\V2',
        ]);

        $this->client->request('GET', '/api/dtos?namespace=V1', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
        ]);

        self::assertResponseIsSuccessful();

        $items = $this->getDataArray();
        self::assertCount(1, $items);
        self::assertSame('Dto1', $items[0]['className']);
    }

    // ==================== SHOW ENDPOINT TESTS ====================

    public function testShowReturnsDto(): void
    {
        $schema = ApiSchemaFactory::createOne(['token' => $this->token]);
        $dto = GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'className' => 'GetUsersResponse',
            'namespace' => 'App\\Dto\\Generated',
        ]);

        $this->client->request('GET', '/api/dtos/' . $dto->getId()->toRfc4122(), [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
        ]);

        self::assertResponseIsSuccessful();

        $data = $this->getJsonResponse();
        self::assertSame('GetUsersResponse', $data['className']);
        self::assertSame('App\\Dto\\Generated', $data['namespace']);
        self::assertArrayHasKey('phpCode', $data);
        self::assertIsString($data['phpCode']);
        self::assertStringContainsString('<?php', $data['phpCode']);
    }

    public function testShowReturns404ForNonExistentDto(): void
    {
        $this->client->request('GET', '/api/dtos/01913a4c-5b6d-7e8f-9a0b-1c2d3e4f5a6b', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
        ]);

        self::assertResponseStatusCodeSame(404);
        $this->assertErrorResponse('DTO not found');
    }

    public function testShowReturns403ForDtoFromOtherToken(): void
    {
        $otherToken = ApiTokenFactory::createOne();
        $otherSchema = ApiSchemaFactory::createOne(['token' => $otherToken]);
        $dto = GeneratedDtoFactory::createOne(['schema' => $otherSchema]);

        $this->client->request('GET', '/api/dtos/' . $dto->getId()->toRfc4122(), [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
        ]);

        self::assertResponseStatusCodeSame(403);
        $this->assertErrorResponse('You do not have access to this DTO');
    }

    public function testShowReturnsPhpFormatWhenRequested(): void
    {
        $schema = ApiSchemaFactory::createOne(['token' => $this->token]);
        $dto = GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'className' => 'GetUsersResponse',
        ]);

        $this->client->request('GET', '/api/dtos/' . $dto->getId()->toRfc4122() . '?format=php', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/x-php', $this->client->getResponse()->headers->get('Content-Type') ?? '');
        self::assertResponseHeaderSame('X-DTO-Class-Name', 'GetUsersResponse');

        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString('<?php', $content ?: '');
    }

    public function testShowReturnsBase64FormatWhenRequested(): void
    {
        $schema = ApiSchemaFactory::createOne(['token' => $this->token]);
        $dto = GeneratedDtoFactory::createOne(['schema' => $schema]);

        $this->client->request('GET', '/api/dtos/' . $dto->getId()->toRfc4122() . '?format=base64', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
        ]);

        self::assertResponseIsSuccessful();

        $data = $this->getJsonResponse();
        self::assertArrayHasKey('phpCodeBase64', $data);
        self::assertArrayNotHasKey('phpCode', $data);

        self::assertIsString($data['phpCodeBase64']);
        $decoded = base64_decode($data['phpCodeBase64'], true);
        self::assertNotFalse($decoded);
        self::assertStringContainsString('<?php', $decoded);
    }

    public function testShowReturnsSpecificVersion(): void
    {
        $schema1 = ApiSchemaFactory::createOne(['token' => $this->token]);
        $schema2 = ApiSchemaFactory::createOne(['token' => $this->token]);

        $v1 = GeneratedDtoFactory::createOne([
            'schema' => $schema1,
            'className' => 'TestDtoV1',
            'version' => 1,
            'isCurrent' => false,
        ]);

        $v2 = GeneratedDtoFactory::createOne([
            'schema' => $schema1,
            'className' => 'TestDtoV2',
            'version' => 2,
            'isCurrent' => true,
        ]);

        $this->client->request('GET', '/api/dtos/' . $v2->getId()->toRfc4122() . '?version=1', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
        ]);

        self::assertResponseIsSuccessful();

        $data = $this->getJsonResponse();
        self::assertSame(1, $data['version']);
    }

    // ==================== DOWNLOAD ENDPOINT TESTS ====================

    public function testDownloadReturnsPhpFile(): void
    {
        $schema = ApiSchemaFactory::createOne(['token' => $this->token]);
        $dto = GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'className' => 'GetUsersResponse',
        ]);

        $this->client->request('GET', '/api/dtos/' . $dto->getId()->toRfc4122() . '/download', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/x-php', $this->client->getResponse()->headers->get('Content-Type') ?? '');
        $contentDisposition = $this->client->getResponse()->headers->get('Content-Disposition') ?? '';
        self::assertStringContainsString('attachment', $contentDisposition);
        self::assertStringContainsString('GetUsersResponse.php', $contentDisposition);
    }

    public function testDownloadReturns403ForDtoFromOtherToken(): void
    {
        $otherToken = ApiTokenFactory::createOne();
        $otherSchema = ApiSchemaFactory::createOne(['token' => $otherToken]);
        $dto = GeneratedDtoFactory::createOne(['schema' => $otherSchema]);

        $this->client->request('GET', '/api/dtos/' . $dto->getId()->toRfc4122() . '/download', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    // ==================== GENERATE ENDPOINT TESTS ====================

    public function testGenerateQueuesMessage(): void
    {
        $schema = ApiSchemaFactory::createOne(['token' => $this->token]);

        $this->client->request(
            'POST',
            '/api/dtos/generate',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode(['schema_id' => $schema->getId()->toRfc4122()], JSON_THROW_ON_ERROR)
        );

        self::assertResponseStatusCodeSame(202);

        $data = $this->getJsonResponse();
        self::assertSame('DTO generation has been queued', $data['message']);
        self::assertSame($schema->getId()->toRfc4122(), $data['schema_id']);

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $messages = $transport->getSent();
        self::assertCount(1, $messages);
    }

    public function testGenerateReturns400WhenSchemaIdMissing(): void
    {
        $this->client->request(
            'POST',
            '/api/dtos/generate',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([], JSON_THROW_ON_ERROR)
        );

        self::assertResponseStatusCodeSame(400);
        $this->assertErrorResponse('schema_id is required');
    }

    public function testGenerateReturns400WhenBodyEmpty(): void
    {
        $this->client->request(
            'POST',
            '/api/dtos/generate',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
                'CONTENT_TYPE' => 'application/json',
            ],
            ''
        );

        self::assertResponseStatusCodeSame(400);
        $this->assertErrorResponse('Request body is required');
    }

    public function testGenerateReturns400WhenJsonInvalid(): void
    {
        $this->client->request(
            'POST',
            '/api/dtos/generate',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
                'CONTENT_TYPE' => 'application/json',
            ],
            'not valid json'
        );

        self::assertResponseStatusCodeSame(400);
        $this->assertErrorResponse('Invalid JSON in request body');
    }

    public function testGenerateReturns404WhenSchemaNotFound(): void
    {
        $this->client->request(
            'POST',
            '/api/dtos/generate',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode(['schema_id' => '01913a4c-5b6d-7e8f-9a0b-1c2d3e4f5a6b'], JSON_THROW_ON_ERROR)
        );

        self::assertResponseStatusCodeSame(404);
        $this->assertErrorResponse('Schema not found');
    }

    public function testGenerateReturns403WhenSchemaFromOtherToken(): void
    {
        $otherToken = ApiTokenFactory::createOne();
        $otherSchema = ApiSchemaFactory::createOne(['token' => $otherToken]);

        $this->client->request(
            'POST',
            '/api/dtos/generate',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode(['schema_id' => $otherSchema->getId()->toRfc4122()], JSON_THROW_ON_ERROR)
        );

        self::assertResponseStatusCodeSame(403);
        $this->assertErrorResponse('You do not have access to this schema');
    }

    // ==================== RESPONSE STRUCTURE TESTS ====================

    public function testListResponseContainsCorrectStructure(): void
    {
        $schema = ApiSchemaFactory::createOne([
            'token' => $this->token,
            'targetHost' => 'api.example.com',
            'endpointPath' => '/users',
            'httpMethod' => 'GET',
        ]);
        $dto = GeneratedDtoFactory::createOne([
            'schema' => $schema,
            'className' => 'GetUsersResponse',
            'namespace' => 'App\\Dto\\Generated',
            'version' => 1,
            'isCurrent' => true,
        ]);

        $this->client->request('GET', '/api/dtos', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
        ]);

        self::assertResponseIsSuccessful();

        $data = $this->getJsonResponse();
        self::assertArrayHasKey('data', $data);
        self::assertIsArray($data['data']);
        self::assertNotEmpty($data['data']);

        /** @var array<string, mixed> $item */
        $item = $data['data'][0];

        self::assertArrayHasKey('id', $item);
        self::assertArrayHasKey('className', $item);
        self::assertArrayHasKey('namespace', $item);
        self::assertArrayHasKey('fullyQualifiedClassName', $item);
        self::assertArrayHasKey('version', $item);
        self::assertArrayHasKey('isCurrent', $item);
        self::assertArrayHasKey('checksum', $item);
        self::assertArrayHasKey('status', $item);
        self::assertArrayHasKey('createdAt', $item);
        self::assertArrayHasKey('schema', $item);

        self::assertSame('App\\Dto\\Generated\\GetUsersResponse', $item['fullyQualifiedClassName']);
        self::assertIsArray($item['schema']);
        self::assertSame('api.example.com', $item['schema']['targetHost']);
        self::assertSame('/users', $item['schema']['endpointPath']);
        self::assertSame('GET', $item['schema']['httpMethod']);
    }

    // ==================== HELPER METHODS ====================

    /**
     * @return array<string, mixed>
     */
    private function getJsonResponse(): array
    {
        $content = $this->client->getResponse()->getContent();
        self::assertNotFalse($content);

        $data = json_decode($content, true);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getDataArray(): array
    {
        $response = $this->getJsonResponse();
        self::assertArrayHasKey('data', $response);
        self::assertIsArray($response['data']);

        /** @var list<array<string, mixed>> */
        return $response['data'];
    }

    /**
     * @return array{total: int, limit: int, offset: int, hasMore: bool}
     */
    private function getMetaArray(): array
    {
        $response = $this->getJsonResponse();
        self::assertArrayHasKey('meta', $response);
        self::assertIsArray($response['meta']);

        /** @var array{total: int, limit: int, offset: int, hasMore: bool} */
        return $response['meta'];
    }

    private function assertErrorResponse(string $expectedMessage): void
    {
        $data = $this->getJsonResponse();
        self::assertTrue($data['error'] ?? false);
        self::assertSame($expectedMessage, $data['message'] ?? '');
    }
}
