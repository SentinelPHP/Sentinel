<?php

namespace App\Tests\Factories;

use App\Entity\RequestLog;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<RequestLog>
 */
final class RequestLogFactory extends PersistentObjectFactory
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
        return RequestLog::class;
    }

    /**
     * @see https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#model-factories
     */
    #[\Override]
    protected function defaults(): array|callable
    {
        return [
            'latencyMs' => self::faker()->randomNumber(),
            'requestMethod' => self::faker()->text(10),
            'requestPath' => self::faker()->text(2048),
            'responseStatusCode' => self::faker()->randomNumber(),
            'targetHost' => self::faker()->text(255),
        ];
    }

    /**
     * @see https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#initialization
     */
    #[\Override]
    protected function initialize(): static
    {
        return $this
            // ->afterInstantiate(function(RequestLog $requestLog): void {})
        ;
    }
}
