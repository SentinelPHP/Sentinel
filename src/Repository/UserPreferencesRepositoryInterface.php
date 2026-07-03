<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserPreferences;

interface UserPreferencesRepositoryInterface
{
    public function findByUser(User $user): ?UserPreferences;

    public function save(UserPreferences $preferences): void;
}
