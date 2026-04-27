<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Entity\UserPreferences;
use App\Repository\UserPreferencesRepositoryInterface;

final readonly class UserPreferencesService implements UserPreferencesServiceInterface
{
    public function __construct(
        private UserPreferencesRepositoryInterface $repository,
    ) {
    }

    public function getPreferences(User $user): UserPreferences
    {
        $preferences = $this->repository->findByUser($user);

        if ($preferences === null) {
            $preferences = $this->createDefaultPreferences($user);
            $this->repository->save($preferences);
        }

        return $preferences;
    }

    public function savePreferences(UserPreferences $preferences): void
    {
        $this->repository->save($preferences);
    }

    public function createDefaultPreferences(User $user): UserPreferences
    {
        return new UserPreferences($user);
    }
}
