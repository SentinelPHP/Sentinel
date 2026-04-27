<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ApiSchema;
use SentinelPHP\Dto\Enum\SchemaType;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class CachedApiSchemaRepository implements ApiSchemaRepositoryInterface
{
    private const CACHE_KEY_PREFIX = 'sentinel.schema.master';

    public function __construct(
        private readonly ApiSchemaRepositoryInterface $inner,
        private readonly CacheInterface $cache,
        private readonly int $cacheTtl = 3600,
    ) {
    }

    public function findMasterSchema(
        Uuid $tokenId,
        string $targetHost,
        string $path,
        string $method,
        SchemaType $type,
    ): ?ApiSchema {
        $cacheKey = $this->buildCacheKey($tokenId, $targetHost, $path, $method, $type);

        try {
            /** @var ApiSchema|null */
            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($tokenId, $targetHost, $path, $method, $type): ?ApiSchema {
                $item->expiresAfter($this->cacheTtl);

                return $this->inner->findMasterSchema($tokenId, $targetHost, $path, $method, $type);
            });
        } catch (InvalidArgumentException) {
            return $this->inner->findMasterSchema($tokenId, $targetHost, $path, $method, $type);
        }
    }

    /**
     * @return list<ApiSchema>
     */
    public function findMasterSchemas(
        Uuid $tokenId,
        string $targetHost,
        string $path,
        string $method,
        SchemaType $type,
    ): array {
        // Not cached - used for data integrity fixes
        return $this->inner->findMasterSchemas($tokenId, $targetHost, $path, $method, $type);
    }

    /**
     * @return list<ApiSchema>
     */
    public function findAllVersions(
        Uuid $tokenId,
        string $targetHost,
        string $path,
        string $method,
        SchemaType $type,
    ): array {
        return $this->inner->findAllVersions($tokenId, $targetHost, $path, $method, $type);
    }

    public function findLatestLearned(
        Uuid $tokenId,
        string $targetHost,
        string $path,
        string $method,
        SchemaType $type,
    ): ?ApiSchema {
        return $this->inner->findLatestLearned($tokenId, $targetHost, $path, $method, $type);
    }

    public function invalidateMasterSchema(
        Uuid $tokenId,
        string $targetHost,
        string $path,
        string $method,
        SchemaType $type,
    ): void {
        $cacheKey = $this->buildCacheKey($tokenId, $targetHost, $path, $method, $type);

        try {
            $this->cache->delete($cacheKey);
        } catch (InvalidArgumentException) {
            // Ignore cache deletion failures
        }
    }

    private function buildCacheKey(
        Uuid $tokenId,
        string $targetHost,
        string $path,
        string $method,
        SchemaType $type,
    ): string {
        $hash = hash('xxh128', sprintf(
            '%s|%s|%s|%s',
            $targetHost,
            $path,
            strtoupper($method),
            $type->value
        ));

        return sprintf('%s.%s.%s', self::CACHE_KEY_PREFIX, $tokenId->toRfc4122(), $hash);
    }
}
