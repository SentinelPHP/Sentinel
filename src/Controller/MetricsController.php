<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Metrics\MetricsCollectorInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final class MetricsController
{
    public function __construct(
        private readonly MetricsCollectorInterface $metricsCollector,
        private readonly bool $metricsEnabled = true,
    ) {
    }

    #[Route('/metrics', name: 'metrics', methods: ['GET'])]
    public function __invoke(): Response
    {
        if (!$this->metricsEnabled) {
            return new Response('Metrics disabled', Response::HTTP_NOT_FOUND);
        }

        $output = $this->metricsCollector->getPrometheusOutput();

        return new Response(
            $output,
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
            ]
        );
    }
}
