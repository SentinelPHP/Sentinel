<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\SchemaDriftRepositoryInterface;
use App\Service\AccessControl\AccessControlServiceInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/dashboard')]
#[IsGranted('ROLE_USER')]
final class DashboardEventsController extends AbstractController
{
    public function __construct(
        private readonly SchemaDriftRepositoryInterface $driftRepository,
        private readonly AccessControlServiceInterface $accessControlService,
    ) {
    }

    #[Route('/events/recent', name: 'api_dashboard_events_recent', methods: ['GET'])]
    public function getRecentEvents(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse([], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $sinceParam = $request->query->get('since');
        $since = $sinceParam !== null
            ? new \DateTimeImmutable($sinceParam)
            : new \DateTimeImmutable('-30 seconds');

        $tokens = $this->accessControlService->getAccessibleTokens($user);
        $tokenIds = array_map(fn ($token) => $token->getId(), $tokens);

        if (empty($tokenIds)) {
            return new JsonResponse([]);
        }

        $events = [];

        $recentDrifts = $this->driftRepository->findRecentSince($since, $tokenIds, 20);

        foreach ($recentDrifts as $drift) {
            $schema = $drift->getSchema();
            $token = $drift->getToken();

            $events[] = [
                'type' => 'drift_detected',
                'id' => $drift->getId()->toRfc4122(),
                'severity' => $drift->getSeverity()->value,
                'driftType' => $drift->getDriftType()->value,
                'path' => $drift->getPath(),
                'endpoint' => $schema->getEndpointPath(),
                'method' => $schema->getHttpMethod(),
                'host' => $schema->getTargetHost(),
                'tokenId' => $token->getId()->toRfc4122(),
                'tokenName' => $token->getName(),
                'createdAt' => $drift->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ];
        }

        usort($events, fn ($a, $b) => $b['createdAt'] <=> $a['createdAt']);

        return new JsonResponse($events);
    }
}
