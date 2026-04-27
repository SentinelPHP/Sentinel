<?php

declare(strict_types=1);

namespace App\Service\Drift;

use App\Entity\SchemaDrift;
use App\Entity\User;
use SentinelPHP\Drift\Enum\DriftType;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DriftAcceptanceService implements DriftAcceptanceServiceInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function acceptDrift(SchemaDrift $drift, User $acceptedBy): void
    {
        if (!$this->canAccept($drift)) {
            throw new \InvalidArgumentException('This drift has already been accepted.');
        }

        $schema = $drift->getSchema();

        $updatedJsonSchema = $this->applyDriftToSchema(
            $schema->getJsonSchema(),
            $drift->getDriftType(),
            $drift->getPath(),
            $drift->getActualValue(),
        );

        $schema->setJsonSchema($updatedJsonSchema);
        $schema->incrementVersion();

        $drift->setAcceptedAt(new \DateTimeImmutable());
        $drift->setAcceptedBy($acceptedBy);

        $this->entityManager->flush();
    }

    public function canAccept(SchemaDrift $drift): bool
    {
        return $drift->getAcceptedAt() === null;
    }

    /**
     * @param array<string, mixed> $jsonSchema
     * @param array<string, mixed>|null $actualValue
     * @return array<string, mixed>
     */
    private function applyDriftToSchema(
        array $jsonSchema,
        DriftType $driftType,
        string $path,
        ?array $actualValue,
    ): array {
        $pathParts = explode('.', $path);

        return match ($driftType) {
            DriftType::FieldAdded => $this->addFieldToSchema($jsonSchema, $pathParts, $actualValue),
            DriftType::FieldRemoved => $this->removeFieldFromSchema($jsonSchema, $pathParts),
            DriftType::TypeChanged, DriftType::StructureChanged => $this->updateFieldInSchema($jsonSchema, $pathParts, $actualValue),
        };
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string> $pathParts
     * @param array<string, mixed>|null $value
     * @return array<string, mixed>
     */
    private function addFieldToSchema(array $schema, array $pathParts, ?array $value): array
    {
        if ($pathParts === []) {
            return $schema;
        }

        $key = array_shift($pathParts);

        if ($pathParts === []) {
            $schema[$key] = $value;
        } else {
            /** @var array<string, mixed> $nested */
            $nested = $schema[$key] ?? [];
            $schema[$key] = $this->addFieldToSchema($nested, $pathParts, $value);
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string> $pathParts
     * @return array<string, mixed>
     */
    private function removeFieldFromSchema(array $schema, array $pathParts): array
    {
        if ($pathParts === []) {
            return $schema;
        }

        $key = array_shift($pathParts);

        if ($pathParts === []) {
            unset($schema[$key]);
        } elseif (isset($schema[$key]) && is_array($schema[$key])) {
            /** @var array<string, mixed> $nested */
            $nested = $schema[$key];
            $schema[$key] = $this->removeFieldFromSchema($nested, $pathParts);
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string> $pathParts
     * @param array<string, mixed>|null $value
     * @return array<string, mixed>
     */
    private function updateFieldInSchema(array $schema, array $pathParts, ?array $value): array
    {
        if ($pathParts === []) {
            return $value ?? $schema;
        }

        $key = array_shift($pathParts);

        if ($pathParts === []) {
            $schema[$key] = $value;
        } else {
            /** @var array<string, mixed> $nested */
            $nested = $schema[$key] ?? [];
            $schema[$key] = $this->updateFieldInSchema($nested, $pathParts, $value);
        }

        return $schema;
    }
}
