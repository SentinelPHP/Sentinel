<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\IpAccessCheckerInterface;
use App\Service\HealthCheckServiceInterface;
use App\Service\StatusServiceInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    public function __construct(
        private readonly HealthCheckServiceInterface $healthCheckService,
        private readonly StatusServiceInterface $statusService,
        private readonly IpAccessCheckerInterface $ipAccessChecker,
    ) {
    }

    #[Route(
        path: '/health',
        name: 'health_check',
        methods: ['GET'],
        priority: 100
    )]
    public function health(): JsonResponse
    {
        $status = $this->healthCheckService->getHealthStatus();

        $httpStatus = $status['status'] === 'ok'
            ? Response::HTTP_OK
            : Response::HTTP_SERVICE_UNAVAILABLE;

        return new JsonResponse($status, $httpStatus);
    }

    #[Route(
        path: '/status',
        name: 'status',
        methods: ['GET'],
        priority: 100
    )]
    public function status(Request $request): JsonResponse
    {
        if (!$this->ipAccessChecker->isAllowed($request)) {
            return new JsonResponse(
                ['error' => true, 'message' => 'Access denied'],
                Response::HTTP_FORBIDDEN
            );
        }

        $status = $this->statusService->getStatus();

        return new JsonResponse($status, Response::HTTP_OK);
    }
}
