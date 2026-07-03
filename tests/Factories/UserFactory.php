<?php

namespace App\Tests\Factories;

use App\Entity\User;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<User>
 */
final class UserFactory extends PersistentObjectFactory
{
    public function __construct()
    {
    }

    #[\Override]
    public static function class(): string
    {
        return User::class;
    }

    /**
     * @see https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#model-factories
     */
    #[\Override]
    protected function defaults(): array|callable
    {
        return [
            'email' => self::faker()->unique()->safeEmail(),
            'password' => '$2y$13$hashed_password_placeholder',
            'roles' => [],
        ];
    }

    public function admin(): static
    {
        return $this->with(['roles' => ['ROLE_ADMIN']]);
    }

    public function withEmail(string $email): static
    {
        return $this->with(['email' => $email]);
    }

    public function withPassword(string $hashedPassword): static
    {
        return $this->with(['password' => $hashedPassword]);
    }

    public function withLastLogin(\DateTimeImmutable $lastLogin): static
    {
        return $this->with(['lastLoginAt' => $lastLogin]);
    }

    /**
     * @see https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#initialization
     */
    #[\Override]
    protected function initialize(): static
    {
        return $this;
    }
}
