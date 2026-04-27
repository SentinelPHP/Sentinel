---
description: Add a new Doctrine entity with repository and migration
---

# Add Database Entity

## Steps

1. **Create the entity** in `src/Entity/{Name}.php`:
   ```php
   <?php
   declare(strict_types=1);
   namespace App\Entity;

   use App\Repository\{Name}Repository;
   use Doctrine\ORM\Mapping as ORM;
   use Symfony\Component\Uid\Uuid;

   #[ORM\Entity(repositoryClass: {Name}Repository::class)]
   #[ORM\Table(name: '{table_name}')]
   class {Name}
   {
       #[ORM\Id]
       #[ORM\Column(type: 'uuid')]
       private Uuid $id;

       #[ORM\Column(type: 'datetime_immutable')]
       private \DateTimeImmutable $createdAt;

       public function __construct()
       {
           $this->id = Uuid::v7();
           $this->createdAt = new \DateTimeImmutable();
       }

       public function getId(): Uuid
       {
           return $this->id;
       }

       public function getCreatedAt(): \DateTimeImmutable
       {
           return $this->createdAt;
       }
   }
   ```

2. **Create the repository** in `src/Repository/{Name}Repository.php`:
   ```php
   <?php
   declare(strict_types=1);
   namespace App\Repository;

   use App\Entity\{Name};
   use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
   use Doctrine\Persistence\ManagerRegistry;

   /**
    * @extends ServiceEntityRepository<{Name}>
    */
   final class {Name}Repository extends ServiceEntityRepository
   {
       public function __construct(ManagerRegistry $registry)
       {
           parent::__construct($registry, {Name}::class);
       }
   }
   ```

3. **Generate migration**:
   ```bash
   ddev exec bin/console doctrine:migrations:diff
   ```

4. **Review the generated migration** in `migrations/` directory

5. **Apply migration**:
   ```bash
   ddev exec bin/console doctrine:migrations:migrate --no-interaction
   ```

6. **Create unit tests** for entity methods in `tests/Unit/Entity/{Name}Test.php`

7. **Run static analysis**:
   ```bash
   // turbo
   ddev exec vendor/bin/phpstan analyse src/Entity/{Name}.php src/Repository/{Name}Repository.php
   ```
