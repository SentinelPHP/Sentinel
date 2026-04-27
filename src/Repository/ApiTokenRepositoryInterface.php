<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ApiToken;
use Symfony\Component\Uid\Uuid;

interface ApiTokenRepositoryInterface
{
    public function findByTokenHash(string $tokenHash): ?ApiToken;

    public function findActiveByTokenHash(string $tokenHash): ?ApiToken;

    /**
     * Count tokens by active status for the given token IDs.
     *
     * @param list<Uuid> $tokenIds
     * @return array{total: int, active: int}
     */
    public function countByActiveStatus(array $tokenIds): array;

    /**
     * Find tokens with filters and pagination.
     *
     * @param list<Uuid> $tokenIds
     * @param array{search?: string, mode?: string, isActive?: bool} $filters
     * @return list<ApiToken>
     */
    public function findWithFilters(array $tokenIds, array $filters, int $limit = 25, int $offset = 0): array;

    /**
     * Count tokens with filters.
     *
     * @param list<Uuid> $tokenIds
     * @param array{search?: string, mode?: string, isActive?: bool} $filters
     */
    public function countWithFilters(array $tokenIds, array $filters): int;

    /**
     * Get token statistics (request count, drift count, last activity).
     *
     * @return array{requestCount: int, driftCount: int, lastActivity: ?\DateTimeImmutable}
     */
    public function getTokenStats(Uuid $tokenId): array;
}
