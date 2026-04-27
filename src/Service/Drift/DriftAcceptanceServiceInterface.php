<?php

declare(strict_types=1);

namespace App\Service\Drift;

use App\Entity\SchemaDrift;
use App\Entity\User;

interface DriftAcceptanceServiceInterface
{
    /**
     * Accept a drift and update the master schema accordingly.
     *
     * @throws \InvalidArgumentException If the drift has already been accepted
     */
    public function acceptDrift(SchemaDrift $drift, User $acceptedBy): void;

    /**
     * Check if a drift can be accepted.
     */
    public function canAccept(SchemaDrift $drift): bool;
}
