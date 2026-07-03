<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Form\ApiTokenType;
use App\Repository\ApiTokenRepository;
use App\Repository\RequestLogRepository;
use App\Repository\SchemaDriftRepository;
use App\Security\TokenAuthenticatorInterface;
use App\Service\AccessControl\AccessControlServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/dashboard/tokens')]
#[IsGranted('ROLE_USER')]
final class TokenController extends AbstractController
{
    private const int DEFAULT_PAGE_SIZE = 25;

    public function __construct(
        private readonly ApiTokenRepository $tokenRepository,
        private readonly RequestLogRepository $requestLogRepository,
        private readonly SchemaDriftRepository $driftRepository,
        private readonly AccessControlServiceInterface $accessControlService,
        private readonly TokenAuthenticatorInterface $tokenAuthenticator,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'dashboard_tokens', methods: ['GET'])]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $accessibleTokens = $this->accessControlService->getAccessibleTokens($user);
        $tokenIds = array_map(static fn (ApiToken $token) => $token->getId(), $accessibleTokens);

        $filters = $this->buildFilters($request);
        $page = max(1, $request->query->getInt('page', 1));
        $limit = min(100, max(10, $request->query->getInt('limit', self::DEFAULT_PAGE_SIZE)));
        $offset = ($page - 1) * $limit;

        $tokens = $this->tokenRepository->findWithFilters($tokenIds, $filters, $limit, $offset);
        $totalCount = $this->tokenRepository->countWithFilters($tokenIds, $filters);

        $totalPages = (int) ceil($totalCount / $limit);

        $tokenStats = [];
        foreach ($tokens as $token) {
            $tokenStats[$token->getId()->toRfc4122()] = $this->tokenRepository->getTokenStats($token->getId());
        }

        return $this->render('dashboard/tokens/index.html.twig', [
            'tokens' => $tokens,
            'tokenStats' => $tokenStats,
            'filters' => [
                'search' => $request->query->get('search'),
                'mode' => $request->query->get('mode'),
                'status' => $request->query->get('status'),
            ],
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'totalCount' => $totalCount,
                'totalPages' => $totalPages,
                'hasNextPage' => $page < $totalPages,
                'hasPrevPage' => $page > 1,
            ],
        ]);
    }

    #[Route('/new', name: 'dashboard_tokens_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function new(Request $request): Response
    {
        $token = new ApiToken();
        $form = $this->createForm(ApiTokenType::class, $token);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainToken = bin2hex(random_bytes(32));
            $tokenHash = $this->tokenAuthenticator->hashToken($plainToken);
            $token->setTokenHash($tokenHash);

            $this->entityManager->persist($token);
            $this->entityManager->flush();

            $this->addFlash('token_created', $plainToken);
            $this->addFlash('success', 'API token created successfully.');

            return $this->redirectToRoute('dashboard_tokens_show', ['id' => $token->getId()]);
        }

        return $this->render('dashboard/tokens/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'dashboard_tokens_show', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-f-]+'])]
    public function show(string $id, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $token = $this->tokenRepository->find(Uuid::fromString($id));

        if ($token === null) {
            throw $this->createNotFoundException('Token not found.');
        }

        if (!$this->accessControlService->canViewToken($user, $token)) {
            throw $this->createAccessDeniedException('You do not have access to view this token.');
        }

        $canEdit = $this->isGranted('ROLE_ADMIN');
        $form = null;

        if ($canEdit) {
            $form = $this->createForm(ApiTokenType::class, $token);
            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
                $this->entityManager->flush();
                $this->addFlash('success', 'Token updated successfully.');

                return $this->redirectToRoute('dashboard_tokens_show', ['id' => $id]);
            }
        }

        $stats = $this->tokenRepository->getTokenStats($token->getId());
        $recentRequests = $this->requestLogRepository->getRecentRequestsByToken($token->getId(), 10);
        $recentDrifts = $this->driftRepository->findRecentByToken($token->getId(), 10);

        $createdToken = null;
        /** @var \Symfony\Component\HttpFoundation\Session\Session $session */
        $session = $request->getSession();
        $flashes = $session->getFlashBag()->peek('token_created');
        if (!empty($flashes)) {
            $createdToken = $flashes[0];
            $session->getFlashBag()->get('token_created');
        }

        return $this->render('dashboard/tokens/show.html.twig', [
            'token' => $token,
            'form' => $form,
            'canEdit' => $canEdit,
            'stats' => $stats,
            'recentRequests' => $recentRequests,
            'recentDrifts' => $recentDrifts,
            'createdToken' => $createdToken,
        ]);
    }

    #[Route('/{id}/toggle', name: 'dashboard_tokens_toggle', methods: ['POST'], requirements: ['id' => '[0-9a-f-]+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function toggle(string $id, Request $request): Response
    {
        $token = $this->tokenRepository->find(Uuid::fromString($id));

        if ($token === null) {
            throw $this->createNotFoundException('Token not found.');
        }

        if (!$this->isCsrfTokenValid('toggle_token_' . $id, $request->request->getString('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('dashboard_tokens');
        }

        $token->setIsActive(!$token->isActive());
        $this->entityManager->flush();

        $status = $token->isActive() ? 'activated' : 'deactivated';
        $this->addFlash('success', sprintf('Token "%s" has been %s.', $token->getName(), $status));

        return $this->redirectToRoute('dashboard_tokens');
    }

    #[Route('/{id}/delete', name: 'dashboard_tokens_delete', methods: ['POST'], requirements: ['id' => '[0-9a-f-]+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(string $id, Request $request): Response
    {
        $token = $this->tokenRepository->find(Uuid::fromString($id));

        if ($token === null) {
            throw $this->createNotFoundException('Token not found.');
        }

        if (!$this->isCsrfTokenValid('delete_token_' . $id, $request->request->getString('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('dashboard_tokens_show', ['id' => $id]);
        }

        $tokenName = $token->getName();
        $this->entityManager->remove($token);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Token "%s" has been deleted.', $tokenName));

        return $this->redirectToRoute('dashboard_tokens');
    }

    #[Route('/{id}/activity', name: 'dashboard_tokens_activity', methods: ['GET'], requirements: ['id' => '[0-9a-f-]+'])]
    public function activity(string $id, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $token = $this->tokenRepository->find(Uuid::fromString($id));

        if ($token === null) {
            throw $this->createNotFoundException('Token not found.');
        }

        if (!$this->accessControlService->canViewToken($user, $token)) {
            throw $this->createAccessDeniedException('You do not have access to view this token.');
        }

        $page = max(1, $request->query->getInt('page', 1));
        $limit = 50;
        $offset = ($page - 1) * $limit;

        $requests = $this->requestLogRepository->findByTokenPaginated($token->getId(), $limit, $offset);
        $totalRequests = $this->requestLogRepository->countByToken($token->getId());
        $drifts = $this->driftRepository->findByTokenPaginated($token->getId(), 50, 0);

        $totalPages = (int) ceil($totalRequests / $limit);

        return $this->render('dashboard/tokens/activity.html.twig', [
            'token' => $token,
            'requests' => $requests,
            'drifts' => $drifts,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'totalCount' => $totalRequests,
                'totalPages' => $totalPages,
                'hasNextPage' => $page < $totalPages,
                'hasPrevPage' => $page > 1,
            ],
        ]);
    }

    /**
     * @return array{search?: string, mode?: string, isActive?: bool}
     */
    private function buildFilters(Request $request): array
    {
        $filters = [];

        $search = $request->query->get('search');
        if ($search !== null && $search !== '') {
            $filters['search'] = $search;
        }

        $mode = $request->query->get('mode');
        if ($mode !== null && $mode !== '') {
            $filters['mode'] = $mode;
        }

        $status = $request->query->get('status');
        if ($status === 'active') {
            $filters['isActive'] = true;
        } elseif ($status === 'inactive') {
            $filters['isActive'] = false;
        }

        return $filters;
    }
}
