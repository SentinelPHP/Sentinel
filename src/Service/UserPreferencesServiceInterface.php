<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Entity\UserPreferences;

interface UserPreferencesServiceInterface
{
    /**
     * Get preferences for a user, creating defaults if none exist.
     */
    public function getPreferences(User $user): UserPreferences;

    /**
     * Save user preferences.
     */
    public function savePreferences(UserPreferences $preferences): void;

    /**
     * Create default preferences for a user.
     */
    public function createDefaultPreferences(User $user): UserPreferences;
}
