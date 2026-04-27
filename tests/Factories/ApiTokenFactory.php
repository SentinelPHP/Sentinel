<?php

namespace App\Tests\Factories;

use App\Entity\ApiToken;
use App\Enum\TokenMode;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<ApiToken>
 */
final class ApiTokenFactory extends PersistentObjectFactory
{
    /**
     * @see https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#factories-as-services
     */
    public function __construct()
    {
    }

    #[\Override]
    public static function class(): string
    {
        return ApiToken::class;
    }

    /**
     * @see https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#model-factories
     */
    #[\Override]
    protected function defaults(): array|callable
    {
        return [
            'allowedTargets' => [],
            'isActive' => true,
            'name' => self::faker()->text(255),
            'tokenHash' => hash('sha256', bin2hex(random_bytes(32))),
        ];
    }

    public function withKnownToken(string $plainToken): static
    {
        return $this->with([
            'tokenHash' => hash('sha256', $plainToken),
        ]);
    }

    public function inactive(): static
    {
        return $this->with(['isActive' => false]);
    }

    /**
     * @param list<string> $targets
     */
    public function withAllowedTargets(array $targets): static
    {
        return $this->with(['allowedTargets' => $targets]);
    }

    public function withMode(TokenMode $mode): static
    {
        return $this->with(['mode' => $mode]);
    }

    public function learning(): static
    {
        return $this->withMode(TokenMode::Learning);
    }

    public function validating(): static
    {
        return $this->withMode(TokenMode::Validating);
    }

    public function passive(): static
    {
        return $this->withMode(TokenMode::Passive);
    }

    public function withLearningThreshold(int $threshold): static
    {
        return $this->with(['learningThreshold' => $threshold]);
    }

    public function withAutoSwitchToValidating(bool $autoSwitch = true): static
    {
        return $this->with(['autoSwitchToValidating' => $autoSwitch]);
    }

    /**
     * @see https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#initialization
     */
    #[\Override]
    protected function initialize(): static
    {
        return $this
            // ->afterInstantiate(function(ApiToken $apiToken): void {})
        ;
    }
}
