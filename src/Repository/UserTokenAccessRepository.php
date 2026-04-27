<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Entity\UserTokenAccess;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<UserTokenAccess>
 */
class UserTokenAccessRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserTokenAccess::class);
    }

    public function save(UserTokenAccess $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(UserTokenAccess $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findByUserAndToken(User $user, ApiToken $token): ?UserTokenAccess
    {
        return $this->findOneBy([
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function hasAccess(User $user, ApiToken $token): bool
    {
        return $this->findByUserAndToken($user, $token) !== null;
    }

    /**
     * @return list<ApiToken>
     */
    public function findAccessibleTokens(User $user): array
    {
        $qb = $this->createQueryBuilder('uta')
            ->select('uta', 't')
            ->join('uta.token', 't')
            ->where('uta.user = :user')
            ->setParameter('user', $user)
            ->orderBy('t.name', 'ASC');

        /** @var list<UserTokenAccess> $results */
        $results = $qb->getQuery()->getResult();

        return array_map(fn (UserTokenAccess $access) => $access->getToken(), $results);
    }

    /**
     * @return list<Uuid>
     */
    public function findAccessibleTokenIds(User $user): array
    {
        $qb = $this->createQueryBuilder('uta')
            ->select('IDENTITY(uta.token)')
            ->where('uta.user = :user')
            ->setParameter('user', $user);

        /** @var list<string> $results */
        $results = $qb->getQuery()->getSingleColumnResult();

        return array_map(fn (string $id) => Uuid::fromString($id), $results);
    }

    /**
     * @return list<UserTokenAccess>
     */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['createdAt' => 'DESC']);
    }

    /**
     * @return list<UserTokenAccess>
     */
    public function findByToken(ApiToken $token): array
    {
        return $this->findBy(['token' => $token], ['createdAt' => 'DESC']);
    }

    public function removeByUserAndToken(User $user, ApiToken $token): bool
    {
        $access = $this->findByUserAndToken($user, $token);

        if ($access === null) {
            return false;
        }

        $this->remove($access, true);

        return true;
    }
}
