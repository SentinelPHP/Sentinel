<?php

declare(strict_types=1);

namespace App\Service\AccessControl;

use App\Entity\ApiSchema;
use App\Entity\ApiToken;
use App\Entity\User;

interface AccessControlServiceInterface
{
    /**
     * Check if a user can view a specific token.
     * Admins can view all tokens, regular users need explicit access.
     */
    public function canViewToken(User $user, ApiToken $token): bool;

    /**
     * Check if a user can view a specific schema.
     * Access is determined by the schema's associated token.
     */
    public function canViewSchema(User $user, ApiSchema $schema): bool;

    /**
     * Get all tokens accessible to a user.
     * Admins get all tokens, regular users get only assigned tokens.
     *
     * @return list<ApiToken>
     */
    public function getAccessibleTokens(User $user): array;

    /**
     * Get all schemas accessible to a user.
     * Admins get all schemas, regular users get schemas for assigned tokens.
     *
     * @return list<ApiSchema>
     */
    public function getAccessibleSchemas(User $user): array;

    /**
     * Grant a user access to a token.
     * No-op if access already exists.
     */
    public function grantAccess(User $user, ApiToken $token): void;

    /**
     * Revoke a user's access to a token.
     *
     * @return bool True if access was revoked, false if it didn't exist
     */
    public function revokeAccess(User $user, ApiToken $token): bool;

    /**
     * Check if a user has explicit access to a token (ignoring admin status).
     */
    public function hasExplicitAccess(User $user, ApiToken $token): bool;
}
