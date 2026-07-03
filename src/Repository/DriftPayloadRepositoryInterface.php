<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DriftPayload;
use Symfony\Component\Uid\Uuid;

interface DriftPayloadRepositoryInterface
{
    public function findByRequestLog(Uuid $requestLogId): ?DriftPayload;

    public function countOlderThan(\DateTimeImmutable $cutoff): int;

    public function deleteOlderThan(\DateTimeImmutable $cutoff, int $batchSize): int;
}
