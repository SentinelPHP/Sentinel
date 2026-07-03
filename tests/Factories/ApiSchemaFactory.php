<?php

declare(strict_types=1);

namespace App\Tests\Factories;

use App\Entity\ApiSchema;
use SentinelPHP\Dto\Enum\SchemaType;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<ApiSchema>
 */
final class ApiSchemaFactory extends PersistentObjectFactory
{
    public function __construct()
    {
    }

    #[\Override]
    public static function class(): string
    {
        return ApiSchema::class;
    }

    #[\Override]
    protected function defaults(): array|callable
    {
        return [
            'token' => ApiTokenFactory::new(),
            'targetHost' => self::faker()->domainName(),
            'endpointPath' => '/' . self::faker()->slug(),
            'httpMethod' => self::faker()->randomElement(['GET', 'POST', 'PUT', 'PATCH', 'DELETE']),
            'schemaType' => self::faker()->randomElement(SchemaType::cases()),
            'jsonSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                ],
            ],
            'version' => 1,
            'isMaster' => false,
            'sampleCount' => 1,
        ];
    }

    public function master(): static
    {
        return $this->with(['isMaster' => true]);
    }

    public function forRequest(): static
    {
        return $this->with(['schemaType' => SchemaType::Request]);
    }

    public function forResponse(): static
    {
        return $this->with(['schemaType' => SchemaType::Response]);
    }

    public function withVersion(int $version): static
    {
        return $this->with(['version' => $version]);
    }

    public function withSampleCount(int $sampleCount): static
    {
        return $this->with(['sampleCount' => $sampleCount]);
    }

    /**
     * @param array<string, mixed> $schema
     */
    public function withJsonSchema(array $schema): static
    {
        return $this->with(['jsonSchema' => $schema]);
    }

    public function forEndpoint(string $targetHost, string $path, string $method): static
    {
        return $this->with([
            'targetHost' => $targetHost,
            'endpointPath' => $path,
            'httpMethod' => strtoupper($method),
        ]);
    }

    #[\Override]
    protected function initialize(): static
    {
        return $this;
    }
}
