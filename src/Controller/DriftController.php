<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use SentinelPHP\Drift\Enum\DriftSeverity;
use SentinelPHP\Drift\Enum\DriftType;
use App\Repository\SchemaDriftRepository;
use App\Service\AccessControl\AccessControlServiceInterface;
use App\Service\Drift\DriftAcceptanceServiceInterface;
use SentinelPHP\Drift\Diff\JsonDiffInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/dashboard/drifts')]
#[IsGranted('ROLE_USER')]
class DriftController extends AbstractController
{
    private const int DEFAULT_PAGE_SIZE = 25;

    public function __construct(
        private readonly SchemaDriftRepository $driftRepository,
        private readonly AccessControlServiceInterface $accessControlService,
        private readonly JsonDiffInterface $jsonDiffService,
        private readonly DriftAcceptanceServiceInterface $driftAcceptanceService,
    ) {
    }

    #[Route('', name: 'dashboard_drifts')]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $accessibleTokens = $this->accessControlService->getAccessibleTokens($user);
        $tokenIds = array_map(static fn ($token) => $token->getId(), $accessibleTokens);

        $filters = $this->buildFilters($request, $tokenIds);
        $page = max(1, $request->query->getInt('page', 1));
        $limit = min(100, max(10, $request->query->getInt('limit', self::DEFAULT_PAGE_SIZE)));
        $offset = ($page - 1) * $limit;

        $drifts = $this->driftRepository->findWithFilters($filters, $limit, $offset);
        $totalCount = $this->driftRepository->countWithFilters($filters);
        $severityCounts = $this->driftRepository->countBySeverityWithFilters($filters);

        $totalPages = (int) ceil($totalCount / $limit);

        return $this->render('dashboard/drifts/index.html.twig', [
            'drifts' => $drifts,
            'tokens' => $accessibleTokens,
            'filters' => [
                'severity' => $request->query->get('severity'),
                'driftType' => $request->query->get('drift_type'),
                'tokenId' => $request->query->get('token_id'),
                'from' => $request->query->get('from'),
                'to' => $request->query->get('to'),
            ],
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'totalCount' => $totalCount,
                'totalPages' => $totalPages,
                'hasNextPage' => $page < $totalPages,
                'hasPrevPage' => $page > 1,
            ],
            'severityCounts' => $severityCounts,
            'severities' => DriftSeverity::cases(),
            'driftTypes' => DriftType::cases(),
        ]);
    }

    #[Route('/{id}', name: 'dashboard_drifts_show', requirements: ['id' => '[0-9a-f-]+'])]
    public function show(string $id): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $drift = $this->driftRepository->find(Uuid::fromString($id));

        if ($drift === null) {
            throw $this->createNotFoundException('Drift not found.');
        }

        if (!$this->accessControlService->canViewToken($user, $drift->getToken())) {
            throw $this->createAccessDeniedException('You do not have access to view this drift.');
        }

        $diffResult = $this->jsonDiffService->generateDiff(
            $drift->getExpectedValue(),
            $drift->getActualValue()
        );

        $timeline = $this->driftRepository->findBySchemaId($drift->getSchema()->getId());

        return $this->render('dashboard/drifts/show.html.twig', [
            'drift' => $drift,
            'diffResult' => $diffResult,
            'timeline' => array_slice($timeline, 0, 20),
            'canAccept' => $this->driftAcceptanceService->canAccept($drift),
        ]);
    }

    #[Route('/{id}/accept', name: 'dashboard_drifts_accept', methods: ['POST'], requirements: ['id' => '[0-9a-f-]+'])]
    public function accept(string $id, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $drift = $this->driftRepository->find(Uuid::fromString($id));

        if ($drift === null) {
            throw $this->createNotFoundException('Drift not found.');
        }

        if (!$this->accessControlService->canViewToken($user, $drift->getToken())) {
            throw $this->createAccessDeniedException('You do not have access to accept this drift.');
        }

        if (!$this->isCsrfTokenValid('accept_drift_' . $id, $request->request->getString('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('dashboard_drifts_show', ['id' => $id]);
        }

        if (!$this->driftAcceptanceService->canAccept($drift)) {
            $this->addFlash('warning', 'This drift has already been accepted.');
            return $this->redirectToRoute('dashboard_drifts_show', ['id' => $id]);
        }

        try {
            $this->driftAcceptanceService->acceptDrift($drift, $user);
            $this->addFlash('success', 'Drift accepted and schema updated successfully.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to accept drift: ' . $e->getMessage());
        }

        return $this->redirectToRoute('dashboard_drifts_show', ['id' => $id]);
    }

    /**
     * @param list<Uuid> $accessibleTokenIds
     * @return array{tokenId?: Uuid, severity?: DriftSeverity, driftType?: DriftType, from?: \DateTimeImmutable, to?: \DateTimeImmutable, tokenIds?: list<Uuid>}
     */
    private function buildFilters(Request $request, array $accessibleTokenIds): array
    {
        $filters = [];

        if ($accessibleTokenIds !== []) {
            $filters['tokenIds'] = $accessibleTokenIds;
        }

        $tokenId = $request->query->get('token_id');
        if ($tokenId !== null && $tokenId !== '') {
            $tokenUuid = Uuid::fromString($tokenId);
            if (in_array($tokenUuid, $accessibleTokenIds, false)) {
                $filters['tokenId'] = $tokenUuid;
            }
        }

        $severity = $request->query->get('severity');
        if ($severity !== null && $severity !== '') {
            $filters['severity'] = DriftSeverity::from($severity);
        }

        $driftType = $request->query->get('drift_type');
        if ($driftType !== null && $driftType !== '') {
            $filters['driftType'] = DriftType::from($driftType);
        }

        $from = $request->query->get('from');
        if ($from !== null && $from !== '') {
            $filters['from'] = new \DateTimeImmutable($from . ' 00:00:00');
        }

        $to = $request->query->get('to');
        if ($to !== null && $to !== '') {
            $filters['to'] = new \DateTimeImmutable($to . ' 23:59:59');
        }

        return $filters;
    }
}
