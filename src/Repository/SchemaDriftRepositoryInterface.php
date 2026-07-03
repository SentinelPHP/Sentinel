<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SchemaDrift;
use Symfony\Component\Uid\Uuid;

interface SchemaDriftRepositoryInterface
{
    /**
     * Count drifts by severity since a given date for the specified tokens.
     *
     * @param list<Uuid> $tokenIds
     * @return array<string, int>
     */
    public function countBySeveritySince(\DateTimeInterface $since, array $tokenIds): array;

    /**
     * Find recent drifts for the specified tokens.
     *
     * @param list<Uuid> $tokenIds
     * @return list<SchemaDrift>
     */
    public function findRecentByTokenIds(array $tokenIds, int $limit = 5): array;

    /**
     * Count drifts by severity for a specific host since a given date.
     *
     * @param list<Uuid> $tokenIds
     * @return array{critical: int, warning: int, info: int}
     */
    public function countBySeverityForHost(\DateTimeInterface $since, array $tokenIds, string $host): array;

    /**
     * Find recent drifts for a specific host.
     *
     * @param list<Uuid> $tokenIds
     * @return list<SchemaDrift>
     */
    public function findRecentByHost(array $tokenIds, string $host, int $limit = 10): array;

    /**
     * Find drifts created since a given timestamp.
     *
     * @param list<Uuid> $tokenIds
     * @return list<SchemaDrift>
     */
    public function findRecentSince(\DateTimeInterface $since, array $tokenIds, int $limit = 20): array;
}
