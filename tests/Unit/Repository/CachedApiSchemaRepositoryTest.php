<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Entity\ApiSchema;
use App\Entity\ApiToken;
use SentinelPHP\Dto\Enum\SchemaType;
use App\Repository\ApiSchemaRepositoryInterface;
use App\Repository\CachedApiSchemaRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[CoversClass(CachedApiSchemaRepository::class)]
final class CachedApiSchemaRepositoryTest extends TestCase
{
    #[Test]
    public function itReturnsCachedMasterSchemaOnCacheHit(): void
    {
        $tokenId = Uuid::v7();
        $schema = $this->createSchema();

        $innerRepository = $this->createMock(ApiSchemaRepositoryInterface::class);
        $cache = $this->createMock(CacheInterface::class);

        $cache
            ->expects(self::once())
            ->method('get')
            ->willReturn($schema);

        $innerRepository
            ->expects(self::never())
            ->method('findMasterSchema');

        $repository = new CachedApiSchemaRepository($innerRepository, $cache, 3600);

        $result = $repository->findMasterSchema(
            $tokenId,
            'api.example.com',
            '/users',
            'GET',
            SchemaType::Response
        );

        self::assertSame($schema, $result);
    }

    #[Test]
    public function itFetchesFromInnerRepositoryOnCacheMiss(): void
    {
        $tokenId = Uuid::v7();
        $schema = $this->createSchema();

        $innerRepository = $this->createMock(ApiSchemaRepositoryInterface::class);
        $cache = $this->createMock(CacheInterface::class);

        $cache
            ->expects(self::once())
            ->method('get')
            ->willReturnCallback(function (string $key, callable $callback) use ($schema): ApiSchema {
                $item = $this->createMock(ItemInterface::class);
                $item->expects(self::once())->method('expiresAfter')->with(3600);
                $callback($item);
                return $schema;
            });

        $innerRepository
            ->expects(self::once())
            ->method('findMasterSchema')
            ->with($tokenId, 'api.example.com', '/users', 'GET', SchemaType::Response)
            ->willReturn($schema);

        $repository = new CachedApiSchemaRepository($innerRepository, $cache, 3600);

        $result = $repository->findMasterSchema(
            $tokenId,
            'api.example.com',
            '/users',
            'GET',
            SchemaType::Response
        );

        self::assertSame($schema, $result);
    }

    #[Test]
    public function itReturnsNullWhenNoMasterSchemaExists(): void
    {
        $tokenId = Uuid::v7();

        $innerRepository = $this->createMock(ApiSchemaRepositoryInterface::class);
        $cache = $this->createMock(CacheInterface::class);

        $cache
            ->expects(self::once())
            ->method('get')
            ->willReturnCallback(function (string $key, callable $callback): ?ApiSchema {
                $item = $this->createMock(ItemInterface::class);
                $item->expects(self::once())->method('expiresAfter')->with(3600);
                /** @var ApiSchema|null */
                return $callback($item);
            });

        $innerRepository
            ->expects(self::once())
            ->method('findMasterSchema')
            ->willReturn(null);

        $repository = new CachedApiSchemaRepository($innerRepository, $cache, 3600);

        $result = $repository->findMasterSchema(
            $tokenId,
            'api.example.com',
            '/users',
            'GET',
            SchemaType::Response
        );

        self::assertNull($result);
    }

    #[Test]
    public function itGeneratesConsistentCacheKeys(): void
    {
        $tokenId = Uuid::v7();
        $capturedKeys = [];

        /** @var CacheInterface&MockObject $cache */
        $cache = $this->createMock(CacheInterface::class);
        $cache
            ->expects(self::exactly(2))
            ->method('get')
            ->willReturnCallback(function (string $key, callable $callback) use (&$capturedKeys) {
                $capturedKeys[] = $key;
                $item = $this->createStub(ItemInterface::class);
                return $callback($item);
            });

        /** @var ApiSchemaRepositoryInterface&Stub $innerRepository */
        $innerRepository = $this->createStub(ApiSchemaRepositoryInterface::class);
        $innerRepository->method('findMasterSchema')->willReturn(null);

        $repository = new CachedApiSchemaRepository($innerRepository, $cache, 3600);

        $repository->findMasterSchema($tokenId, 'api.example.com', '/users', 'GET', SchemaType::Response);
        $repository->findMasterSchema($tokenId, 'api.example.com', '/users', 'GET', SchemaType::Response);

        self::assertCount(2, $capturedKeys);
        self::assertSame($capturedKeys[0], $capturedKeys[1]);
        self::assertStringStartsWith('sentinel.schema.master.', $capturedKeys[0]);
    }

    #[Test]
    public function itGeneratesDifferentCacheKeysForDifferentEndpoints(): void
    {
        $tokenId = Uuid::v7();
        $capturedKeys = [];

        /** @var CacheInterface&MockObject $cache */
        $cache = $this->createMock(CacheInterface::class);
        $cache
            ->expects(self::exactly(2))
            ->method('get')
            ->willReturnCallback(function (string $key, callable $callback) use (&$capturedKeys) {
                $capturedKeys[] = $key;
                $item = $this->createStub(ItemInterface::class);
                return $callback($item);
            });

        /** @var ApiSchemaRepositoryInterface&Stub $innerRepository */
        $innerRepository = $this->createStub(ApiSchemaRepositoryInterface::class);
        $innerRepository->method('findMasterSchema')->willReturn(null);

        $repository = new CachedApiSchemaRepository($innerRepository, $cache, 3600);

        $repository->findMasterSchema($tokenId, 'api.example.com', '/users', 'GET', SchemaType::Response);
        $repository->findMasterSchema($tokenId, 'api.example.com', '/posts', 'GET', SchemaType::Response);

        self::assertCount(2, $capturedKeys);
        self::assertNotSame($capturedKeys[0], $capturedKeys[1]);
    }

    #[Test]
    public function itGeneratesDifferentCacheKeysForDifferentSchemaTypes(): void
    {
        $tokenId = Uuid::v7();
        $capturedKeys = [];

        /** @var CacheInterface&MockObject $cache */
        $cache = $this->createMock(CacheInterface::class);
        $cache
            ->expects(self::exactly(2))
            ->method('get')
            ->willReturnCallback(function (string $key, callable $callback) use (&$capturedKeys) {
                $capturedKeys[] = $key;
                $item = $this->createStub(ItemInterface::class);
                return $callback($item);
            });

        /** @var ApiSchemaRepositoryInterface&Stub $innerRepository */
        $innerRepository = $this->createStub(ApiSchemaRepositoryInterface::class);
        $innerRepository->method('findMasterSchema')->willReturn(null);

        $repository = new CachedApiSchemaRepository($innerRepository, $cache, 3600);

        $repository->findMasterSchema($tokenId, 'api.example.com', '/users', 'POST', SchemaType::Request);
        $repository->findMasterSchema($tokenId, 'api.example.com', '/users', 'POST', SchemaType::Response);

        self::assertCount(2, $capturedKeys);
        self::assertNotSame($capturedKeys[0], $capturedKeys[1]);
    }

    #[Test]
    public function itNormalizesHttpMethodToUppercase(): void
    {
        $tokenId = Uuid::v7();
        $capturedKeys = [];

        /** @var CacheInterface&MockObject $cache */
        $cache = $this->createMock(CacheInterface::class);
        $cache
            ->expects(self::exactly(2))
            ->method('get')
            ->willReturnCallback(function (string $key, callable $callback) use (&$capturedKeys) {
                $capturedKeys[] = $key;
                $item = $this->createStub(ItemInterface::class);
                return $callback($item);
            });

        /** @var ApiSchemaRepositoryInterface&Stub $innerRepository */
        $innerRepository = $this->createStub(ApiSchemaRepositoryInterface::class);
        $innerRepository->method('findMasterSchema')->willReturn(null);

        $repository = new CachedApiSchemaRepository($innerRepository, $cache, 3600);

        $repository->findMasterSchema($tokenId, 'api.example.com', '/users', 'get', SchemaType::Response);
        $repository->findMasterSchema($tokenId, 'api.example.com', '/users', 'GET', SchemaType::Response);

        self::assertSame($capturedKeys[0], $capturedKeys[1]);
    }

    #[Test]
    public function itInvalidatesCacheForMasterSchema(): void
    {
        $tokenId = Uuid::v7();

        /** @var CacheInterface&MockObject $cache */
        $cache = $this->createMock(CacheInterface::class);
        $cache
            ->expects(self::once())
            ->method('delete')
            ->with(self::stringStartsWith('sentinel.schema.master.'));

        /** @var ApiSchemaRepositoryInterface&Stub $innerRepository */
        $innerRepository = $this->createStub(ApiSchemaRepositoryInterface::class);

        $repository = new CachedApiSchemaRepository($innerRepository, $cache, 3600);

        $repository->invalidateMasterSchema(
            $tokenId,
            'api.example.com',
            '/users',
            'GET',
            SchemaType::Response
        );
    }

    #[Test]
    public function itDelegatesFindAllVersionsToInnerRepository(): void
    {
        $tokenId = Uuid::v7();
        $schemas = [$this->createSchema(), $this->createSchema()];

        $innerRepository = $this->createMock(ApiSchemaRepositoryInterface::class);
        $cache = $this->createMock(CacheInterface::class);

        $innerRepository
            ->expects(self::once())
            ->method('findAllVersions')
            ->with($tokenId, 'api.example.com', '/users', 'GET', SchemaType::Response)
            ->willReturn($schemas);

        $cache->expects(self::never())->method('get');

        $repository = new CachedApiSchemaRepository($innerRepository, $cache, 3600);

        $result = $repository->findAllVersions(
            $tokenId,
            'api.example.com',
            '/users',
            'GET',
            SchemaType::Response
        );

        self::assertSame($schemas, $result);
    }

    #[Test]
    public function itDelegatesFindLatestLearnedToInnerRepository(): void
    {
        $tokenId = Uuid::v7();
        $schema = $this->createSchema();

        $innerRepository = $this->createMock(ApiSchemaRepositoryInterface::class);
        $cache = $this->createMock(CacheInterface::class);

        $innerRepository
            ->expects(self::once())
            ->method('findLatestLearned')
            ->with($tokenId, 'api.example.com', '/users', 'GET', SchemaType::Response)
            ->willReturn($schema);

        $cache->expects(self::never())->method('get');

        $repository = new CachedApiSchemaRepository($innerRepository, $cache, 3600);

        $result = $repository->findLatestLearned(
            $tokenId,
            'api.example.com',
            '/users',
            'GET',
            SchemaType::Response
        );

        self::assertSame($schema, $result);
    }

    #[Test]
    public function itFallsBackToInnerRepositoryOnCacheException(): void
    {
        $tokenId = Uuid::v7();
        $schema = $this->createSchema();

        $innerRepository = $this->createMock(ApiSchemaRepositoryInterface::class);
        $cache = $this->createMock(CacheInterface::class);

        $cache
            ->expects(self::once())
            ->method('get')
            ->willThrowException(new TestInvalidArgumentException());

        $innerRepository
            ->expects(self::once())
            ->method('findMasterSchema')
            ->willReturn($schema);

        $repository = new CachedApiSchemaRepository($innerRepository, $cache, 3600);

        $result = $repository->findMasterSchema(
            $tokenId,
            'api.example.com',
            '/users',
            'GET',
            SchemaType::Response
        );

        self::assertSame($schema, $result);
    }

    #[Test]
    public function itIgnoresCacheDeleteExceptions(): void
    {
        $tokenId = Uuid::v7();

        /** @var CacheInterface&MockObject $cache */
        $cache = $this->createMock(CacheInterface::class);
        $cache
            ->expects(self::once())
            ->method('delete')
            ->willThrowException(new TestInvalidArgumentException());

        /** @var ApiSchemaRepositoryInterface&Stub $innerRepository */
        $innerRepository = $this->createStub(ApiSchemaRepositoryInterface::class);

        $repository = new CachedApiSchemaRepository($innerRepository, $cache, 3600);

        $repository->invalidateMasterSchema(
            $tokenId,
            'api.example.com',
            '/users',
            'GET',
            SchemaType::Response
        );

        $this->addToAssertionCount(1);
    }

    private function createSchema(): ApiSchema
    {
        $token = new ApiToken();
        $token->setName('Test Token')
            ->setTokenHash(hash('sha256', 'test'));

        $schema = new ApiSchema();
        $schema->setToken($token)
            ->setTargetHost('api.example.com')
            ->setEndpointPath('/users')
            ->setHttpMethod('GET')
            ->setSchemaType(SchemaType::Response)
            ->setJsonSchema(['type' => 'object'])
            ->setIsMaster(true);

        return $schema;
    }
}

class TestInvalidArgumentException extends \Exception implements \Psr\Cache\InvalidArgumentException
{
}
