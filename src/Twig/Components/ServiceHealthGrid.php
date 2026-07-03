<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\User;
use App\Service\Dashboard\ServiceHealthServiceInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent('ServiceHealthGrid')]
final class ServiceHealthGrid
{
    use DefaultActionTrait;

    /** @var list<array{host: string, status: string, avgLatencyMs: int, requestCount: int, errorRate: float, criticalDrifts: int, warningDrifts: int}>|null */
    private ?array $cachedServices = null;

    public function __construct(
        private readonly ServiceHealthServiceInterface $serviceHealthService,
        private readonly Security $security,
    ) {
    }

    /**
     * @return list<array{host: string, status: string, avgLatencyMs: int, requestCount: int, errorRate: float, criticalDrifts: int, warningDrifts: int}>
     */
    public function getServices(): array
    {
        if ($this->cachedServices !== null) {
            return $this->cachedServices;
        }

        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return [];
        }

        $this->cachedServices = $this->serviceHealthService->getAllServicesHealth($user);

        return $this->cachedServices;
    }

    #[LiveAction]
    public function refresh(): void
    {
        $this->cachedServices = null;
    }

    /**
     * @return array{green: int, yellow: int, red: int}
     */
    public function getStatusCounts(): array
    {
        $services = $this->getServices();

        $green = 0;
        $yellow = 0;
        $red = 0;

        foreach ($services as $service) {
            match ($service['status']) {
                'green' => $green++,
                'yellow' => $yellow++,
                'red' => $red++,
                default => null,
            };
        }

        return ['green' => $green, 'yellow' => $yellow, 'red' => $red];
    }
}
