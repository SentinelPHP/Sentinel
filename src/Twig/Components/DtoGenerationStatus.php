<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\ApiSchema;
use App\Entity\GeneratedDto;
use App\Repository\ApiSchemaRepository;
use App\Repository\GeneratedDtoRepository;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent('DtoGenerationStatus')]
final class DtoGenerationStatus
{
    use DefaultActionTrait;

    #[LiveProp]
    public ?string $schemaId = null;

    #[LiveProp]
    public ?string $dtoId = null;

    private ?GeneratedDto $cachedDto = null;

    public function __construct(
        private readonly GeneratedDtoRepository $dtoRepository,
        private readonly ApiSchemaRepository $schemaRepository,
    ) {
    }

    public function getDto(): ?GeneratedDto
    {
        if ($this->cachedDto !== null) {
            return $this->cachedDto;
        }

        if ($this->dtoId !== null) {
            $this->cachedDto = $this->dtoRepository->find($this->dtoId);
            return $this->cachedDto;
        }

        if ($this->schemaId !== null) {
            /** @var ApiSchema|null $schema */
            $schema = $this->schemaRepository->find($this->schemaId);

            if ($schema !== null) {
                $this->cachedDto = $this->dtoRepository->findCurrentBySchema($schema);
            }
        }

        return $this->cachedDto;
    }

    public function getStatus(): string
    {
        $dto = $this->getDto();

        if ($dto === null) {
            return 'none';
        }

        return $dto->getStatus()->value;
    }

    public function getStatusBadgeClass(): string
    {
        return match ($this->getStatus()) {
            'completed' => 'bg-success',
            'pending' => 'bg-warning text-dark',
            'in_progress' => 'bg-info',
            'failed' => 'bg-danger',
            default => 'bg-secondary',
        };
    }

    public function getStatusIcon(): string
    {
        return match ($this->getStatus()) {
            'completed' => 'bi-check-circle',
            'pending' => 'bi-hourglass-split',
            'in_progress' => 'bi-arrow-repeat',
            'failed' => 'bi-x-circle',
            default => 'bi-question-circle',
        };
    }

    public function getStatusLabel(): string
    {
        return match ($this->getStatus()) {
            'completed' => 'Generated',
            'pending' => 'Pending',
            'in_progress' => 'Generating...',
            'failed' => 'Failed',
            default => 'No DTO',
        };
    }

    public function isInProgress(): bool
    {
        $status = $this->getStatus();
        return $status === 'pending' || $status === 'in_progress';
    }
}
