<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserPreferences;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserPreferences>
 */
final class UserPreferencesRepository extends ServiceEntityRepository implements UserPreferencesRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserPreferences::class);
    }

    public function findByUser(User $user): ?UserPreferences
    {
        return $this->findOneBy(['user' => $user]);
    }

    public function save(UserPreferences $preferences): void
    {
        $this->getEntityManager()->persist($preferences);
        $this->getEntityManager()->flush();
    }
}
