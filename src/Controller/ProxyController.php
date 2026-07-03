<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ProxyService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProxyController
{
    public function __construct(
        private readonly ProxyService $proxyService,
    ) {
    }

    #[Route(
        path: '/{path}',
        name: 'proxy_catchall',
        requirements: ['path' => '.*'],
        methods: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'],
        priority: -100
    )]
    public function proxy(Request $request): Response
    {
        return $this->proxyService->proxy($request);
    }
}
