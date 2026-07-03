<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Service\AccessControl\AccessControlServiceInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, ApiToken>
 */
final class TokenVoter extends Voter
{
    public const VIEW = 'VIEW';
    public const EDIT = 'EDIT';
    public const DELETE = 'DELETE';

    public function __construct(
        private readonly AccessControlServiceInterface $accessControlService,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE], true)
            && $subject instanceof ApiToken;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var ApiToken $apiToken */
        $apiToken = $subject;

        return match ($attribute) {
            self::VIEW => $this->canView($user, $apiToken),
            self::EDIT, self::DELETE => $this->canEdit($user, $apiToken),
            default => false,
        };
    }

    private function canView(User $user, ApiToken $apiToken): bool
    {
        return $this->accessControlService->canViewToken($user, $apiToken);
    }

    private function canEdit(User $user, ApiToken $apiToken): bool
    {
        // Only admins can edit/delete tokens
        return $user->isAdmin();
    }
}
