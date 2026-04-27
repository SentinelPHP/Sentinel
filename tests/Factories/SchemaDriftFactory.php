<?php

declare(strict_types=1);

namespace App\Tests\Factories;

use App\Entity\SchemaDrift;
use SentinelPHP\Drift\Enum\DriftSeverity;
use SentinelPHP\Drift\Enum\DriftType;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<SchemaDrift>
 */
final class SchemaDriftFactory extends PersistentObjectFactory
{
    public function __construct()
    {
    }

    #[\Override]
    public static function class(): string
    {
        return SchemaDrift::class;
    }

    #[\Override]
    protected function defaults(): array|callable
    {
        return [
            'schema' => ApiSchemaFactory::new(),
            'token' => ApiTokenFactory::new(),
            'requestLog' => null,
            'driftType' => self::faker()->randomElement(DriftType::cases()),
            'path' => '$.' . self::faker()->slug(),
            'expectedValue' => ['type' => 'string'],
            'actualValue' => ['type' => 'integer'],
            'severity' => self::faker()->randomElement(DriftSeverity::cases()),
        ];
    }

    public function withRequestLog(): static
    {
        return $this->with(['requestLog' => RequestLogFactory::new()]);
    }

    public function fieldAdded(): static
    {
        return $this->with([
            'driftType' => DriftType::FieldAdded,
            'expectedValue' => null,
            'actualValue' => ['type' => 'string'],
        ]);
    }

    public function fieldRemoved(): static
    {
        return $this->with([
            'driftType' => DriftType::FieldRemoved,
            'expectedValue' => ['type' => 'string'],
            'actualValue' => null,
        ]);
    }

    public function typeChanged(): static
    {
        return $this->with([
            'driftType' => DriftType::TypeChanged,
            'expectedValue' => ['type' => 'string'],
            'actualValue' => ['type' => 'integer'],
        ]);
    }

    public function structureChanged(): static
    {
        return $this->with([
            'driftType' => DriftType::StructureChanged,
            'expectedValue' => ['type' => 'object'],
            'actualValue' => ['type' => 'array'],
        ]);
    }

    public function info(): static
    {
        return $this->with(['severity' => DriftSeverity::Info]);
    }

    public function warning(): static
    {
        return $this->with(['severity' => DriftSeverity::Warning]);
    }

    public function critical(): static
    {
        return $this->with(['severity' => DriftSeverity::Critical]);
    }

    public function atPath(string $path): static
    {
        return $this->with(['path' => $path]);
    }

    public function createdAt(\DateTimeImmutable $createdAt): static
    {
        return $this->afterInstantiate(function (SchemaDrift $drift) use ($createdAt): void {
            $reflection = new \ReflectionProperty(SchemaDrift::class, 'createdAt');
            $reflection->setValue($drift, $createdAt);
        });
    }

    #[\Override]
    protected function initialize(): static
    {
        return $this;
    }
}
