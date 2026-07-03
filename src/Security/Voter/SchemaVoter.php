<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\ApiSchema;
use App\Entity\User;
use App\Service\AccessControl\AccessControlServiceInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, ApiSchema>
 */
final class SchemaVoter extends Voter
{
    public const VIEW = 'VIEW';
    public const EDIT = 'EDIT';

    public function __construct(
        private readonly AccessControlServiceInterface $accessControlService,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT], true)
            && $subject instanceof ApiSchema;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var ApiSchema $schema */
        $schema = $subject;

        return match ($attribute) {
            self::VIEW => $this->canView($user, $schema),
            self::EDIT => $this->canEdit($user, $schema),
            default => false,
        };
    }

    private function canView(User $user, ApiSchema $schema): bool
    {
        return $this->accessControlService->canViewSchema($user, $schema);
    }

    private function canEdit(User $user, ApiSchema $schema): bool
    {
        // Only admins can edit schemas (promote to master, etc.)
        return $user->isAdmin();
    }
}
