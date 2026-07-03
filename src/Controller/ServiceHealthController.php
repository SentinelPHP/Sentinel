<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Dashboard\LatencyMetricsServiceInterface;
use App\Service\Dashboard\ServiceHealthServiceInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/dashboard/services')]
#[IsGranted('ROLE_USER')]
class ServiceHealthController extends AbstractController
{
    public function __construct(
        private readonly ServiceHealthServiceInterface $serviceHealthService,
        private readonly LatencyMetricsServiceInterface $latencyMetricsService,
    ) {
    }

    #[Route('', name: 'dashboard_services')]
    public function index(): Response
    {
        return $this->render('dashboard/services/index.html.twig');
    }

    #[Route('/{host}', name: 'dashboard_services_show', requirements: ['host' => '.+'])]
    public function show(string $host): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $serviceHealth = $this->serviceHealthService->getServiceHealthByHost($user, $host);

        if ($serviceHealth === null) {
            throw $this->createNotFoundException('Service not found or no data available.');
        }

        $history = $this->serviceHealthService->getHealthHistory(
            $user,
            $host,
            new \DateTimeImmutable('-24 hours')
        );

        return $this->render('dashboard/services/show.html.twig', [
            'service' => $serviceHealth,
            'history' => $history,
        ]);
    }

    #[Route('/{host}/latency', name: 'dashboard_services_latency', requirements: ['host' => '.+'])]
    public function latency(string $host, Request $request): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $serviceHealth = $this->serviceHealthService->getServiceHealthByHost($user, $host);

        if ($serviceHealth === null) {
            throw $this->createNotFoundException('Service not found or no data available.');
        }

        $baselineType = $request->query->getString('baseline', '24h');
        $period = new \DateInterval('PT1H');

        $currentStart = new \DateTimeImmutable('-1 hour');
        $baselineStart = match ($baselineType) {
            '7d' => new \DateTimeImmutable('-7 days -1 hour'),
            '30d' => new \DateTimeImmutable('-30 days -1 hour'),
            default => new \DateTimeImmutable('-24 hours -1 hour'),
        };

        $comparison = $this->latencyMetricsService->getLatencyComparison(
            $user,
            $host,
            $currentStart,
            $baselineStart,
            $period
        );

        $percentiles = $this->latencyMetricsService->getPercentiles($user, $host);
        $rollingAverages = $this->latencyMetricsService->getRollingAverages($user, $host);
        $trend = $this->latencyMetricsService->getTrend($user, $host);

        $timeSeries = $this->latencyMetricsService->getLatencyTimeSeries(
            $user,
            $host,
            new \DateTimeImmutable('-6 hours'),
            '5m'
        );

        return $this->render('dashboard/services/latency.html.twig', [
            'service' => $serviceHealth,
            'host' => $host,
            'comparison' => $comparison,
            'percentiles' => $percentiles,
            'rollingAverages' => $rollingAverages,
            'trend' => $trend,
            'timeSeries' => $timeSeries,
            'baselineType' => $baselineType,
        ]);
    }
}
