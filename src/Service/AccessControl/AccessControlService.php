<?php

declare(strict_types=1);

namespace App\Service\AccessControl;

use App\Entity\ApiSchema;
use App\Entity\ApiToken;
use App\Entity\User;
use App\Entity\UserTokenAccess;
use App\Repository\ApiSchemaRepository;
use App\Repository\ApiTokenRepository;
use App\Repository\UserTokenAccessRepository;
use Symfony\Component\Uid\Uuid;

final readonly class AccessControlService implements AccessControlServiceInterface
{
    public function __construct(
        private UserTokenAccessRepository $userTokenAccessRepository,
        private ApiTokenRepository $apiTokenRepository,
        private ApiSchemaRepository $apiSchemaRepository,
    ) {
    }

    public function canViewToken(User $user, ApiToken $token): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $this->userTokenAccessRepository->hasAccess($user, $token);
    }

    public function canViewSchema(User $user, ApiSchema $schema): bool
    {
        return $this->canViewToken($user, $schema->getToken());
    }

    public function getAccessibleTokens(User $user): array
    {
        if ($user->isAdmin()) {
            return $this->apiTokenRepository->findBy([], ['name' => 'ASC']);
        }

        return $this->userTokenAccessRepository->findAccessibleTokens($user);
    }

    public function getAccessibleSchemas(User $user): array
    {
        if ($user->isAdmin()) {
            return $this->apiSchemaRepository->findBy([], ['updatedAt' => 'DESC']);
        }

        $tokenIds = $this->userTokenAccessRepository->findAccessibleTokenIds($user);

        if (empty($tokenIds)) {
            return [];
        }

        return $this->findSchemasByTokenIds($tokenIds);
    }

    public function grantAccess(User $user, ApiToken $token): void
    {
        if ($this->userTokenAccessRepository->hasAccess($user, $token)) {
            return;
        }

        $access = new UserTokenAccess($user, $token);
        $this->userTokenAccessRepository->save($access, true);
    }

    public function revokeAccess(User $user, ApiToken $token): bool
    {
        return $this->userTokenAccessRepository->removeByUserAndToken($user, $token);
    }

    public function hasExplicitAccess(User $user, ApiToken $token): bool
    {
        return $this->userTokenAccessRepository->hasAccess($user, $token);
    }

    /**
     * @param list<Uuid> $tokenIds
     * @return list<ApiSchema>
     */
    private function findSchemasByTokenIds(array $tokenIds): array
    {
        $qb = $this->apiSchemaRepository->createQueryBuilder('s')
            ->leftJoin('s.token', 't')
            ->addSelect('t')
            ->where('s.token IN (:tokenIds)')
            ->setParameter('tokenIds', $tokenIds)
            ->orderBy('s.updatedAt', 'DESC');

        /** @var list<ApiSchema> $results */
        $results = $qb->getQuery()->getResult();

        return $results;
    }
}
