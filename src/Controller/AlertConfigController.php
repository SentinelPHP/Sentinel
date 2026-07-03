<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AlertConfiguration;
use App\Form\AlertConfigurationType;
use App\Repository\AlertConfigurationRepository;
use App\Repository\AlertLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Service\Alert\AlertTestServiceInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/dashboard/alerts')]
#[IsGranted('ROLE_USER')]
final class AlertConfigController extends AbstractController
{
    private const int DEFAULT_PAGE_SIZE = 25;

    public function __construct(
        private readonly AlertConfigurationRepository $alertConfigRepository,
        private readonly AlertLogRepository $alertLogRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AlertTestServiceInterface $alertTestService,
    ) {
    }

    #[Route('', name: 'dashboard_alerts', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filters = $this->buildFilters($request);
        $page = max(1, $request->query->getInt('page', 1));
        $limit = min(100, max(10, $request->query->getInt('limit', self::DEFAULT_PAGE_SIZE)));
        $offset = ($page - 1) * $limit;

        $alerts = $this->alertConfigRepository->findWithFilters($filters, $limit, $offset);
        $totalCount = $this->alertConfigRepository->countWithFilters($filters);

        $totalPages = (int) ceil($totalCount / $limit);

        return $this->render('dashboard/alerts/index.html.twig', [
            'alerts' => $alerts,
            'filters' => [
                'channelType' => $request->query->get('channelType'),
                'status' => $request->query->get('status'),
                'scope' => $request->query->get('scope'),
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

    #[Route('/new', name: 'dashboard_alerts_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function new(Request $request): Response
    {
        $alert = new AlertConfiguration();
        $form = $this->createForm(AlertConfigurationType::class, $alert);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($alert);
            $this->entityManager->flush();

            $this->addFlash('success', 'Alert configuration created successfully.');

            return $this->redirectToRoute('dashboard_alerts_edit', ['id' => $alert->getId()]);
        }

        return $this->render('dashboard/alerts/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'dashboard_alerts_edit', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-f-]+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(string $id, Request $request): Response
    {
        $alert = $this->alertConfigRepository->find(Uuid::fromString($id));

        if ($alert === null) {
            throw $this->createNotFoundException('Alert configuration not found.');
        }

        $form = $this->createForm(AlertConfigurationType::class, $alert);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();
            $this->addFlash('success', 'Alert configuration updated successfully.');

            return $this->redirectToRoute('dashboard_alerts_edit', ['id' => $id]);
        }

        return $this->render('dashboard/alerts/edit.html.twig', [
            'alert' => $alert,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/toggle', name: 'dashboard_alerts_toggle', methods: ['POST'], requirements: ['id' => '[0-9a-f-]+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function toggle(string $id, Request $request): Response
    {
        $alert = $this->alertConfigRepository->find(Uuid::fromString($id));

        if ($alert === null) {
            throw $this->createNotFoundException('Alert configuration not found.');
        }

        if (!$this->isCsrfTokenValid('toggle_alert_' . $id, $request->request->getString('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('dashboard_alerts');
        }

        $alert->setIsActive(!$alert->isActive());
        $this->entityManager->flush();

        $status = $alert->isActive() ? 'activated' : 'deactivated';
        $this->addFlash('success', sprintf('Alert configuration has been %s.', $status));

        return $this->redirectToRoute('dashboard_alerts');
    }

    #[Route('/{id}/delete', name: 'dashboard_alerts_delete', methods: ['POST'], requirements: ['id' => '[0-9a-f-]+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(string $id, Request $request): Response
    {
        $alert = $this->alertConfigRepository->find(Uuid::fromString($id));

        if ($alert === null) {
            throw $this->createNotFoundException('Alert configuration not found.');
        }

        if (!$this->isCsrfTokenValid('delete_alert_' . $id, $request->request->getString('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('dashboard_alerts_edit', ['id' => $id]);
        }

        $this->entityManager->remove($alert);
        $this->entityManager->flush();

        $this->addFlash('success', 'Alert configuration has been deleted.');

        return $this->redirectToRoute('dashboard_alerts');
    }

    #[Route('/{id}/mute', name: 'dashboard_alerts_mute', methods: ['POST'], requirements: ['id' => '[0-9a-f-]+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function mute(string $id, Request $request): Response
    {
        $alert = $this->alertConfigRepository->find(Uuid::fromString($id));

        if ($alert === null) {
            throw $this->createNotFoundException('Alert configuration not found.');
        }

        if (!$this->isCsrfTokenValid('mute_alert_' . $id, $request->request->getString('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('dashboard_alerts');
        }

        $duration = $request->request->getString('duration', '1h');
        $reason = $request->request->getString('reason', '');

        $mutedUntil = match ($duration) {
            '1h' => new \DateTimeImmutable('+1 hour'),
            '4h' => new \DateTimeImmutable('+4 hours'),
            '24h' => new \DateTimeImmutable('+24 hours'),
            '7d' => new \DateTimeImmutable('+7 days'),
            default => new \DateTimeImmutable('+1 hour'),
        };

        $alert->mute($mutedUntil, $reason !== '' ? $reason : null);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Alert configuration muted until %s.', $mutedUntil->format('Y-m-d H:i')));

        return $this->redirectToRoute('dashboard_alerts');
    }

    #[Route('/{id}/unmute', name: 'dashboard_alerts_unmute', methods: ['POST'], requirements: ['id' => '[0-9a-f-]+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function unmute(string $id, Request $request): Response
    {
        $alert = $this->alertConfigRepository->find(Uuid::fromString($id));

        if ($alert === null) {
            throw $this->createNotFoundException('Alert configuration not found.');
        }

        if (!$this->isCsrfTokenValid('unmute_alert_' . $id, $request->request->getString('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('dashboard_alerts');
        }

        $alert->unmute();
        $this->entityManager->flush();

        $this->addFlash('success', 'Alert configuration has been unmuted.');

        return $this->redirectToRoute('dashboard_alerts');
    }

    #[Route('/{id}/test', name: 'dashboard_alerts_test', methods: ['POST'], requirements: ['id' => '[0-9a-f-]+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function test(string $id, Request $request): JsonResponse
    {
        $alert = $this->alertConfigRepository->find(Uuid::fromString($id));

        if ($alert === null) {
            return new JsonResponse(['success' => false, 'message' => 'Alert configuration not found.'], 404);
        }

        /** @var array{_token?: string}|null $data */
        $data = json_decode($request->getContent(), true);
        $csrfToken = $data['_token'] ?? '';

        if (!$this->isCsrfTokenValid('test_alert_' . $id, $csrfToken)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid CSRF token.'], 403);
        }

        $result = $this->alertTestService->sendTestAlert($alert);

        return new JsonResponse([
            'success' => $result->isSuccess(),
            'message' => $result->getMessage(),
        ]);
    }

    #[Route('/history', name: 'dashboard_alerts_history', methods: ['GET'])]
    public function history(Request $request): Response
    {
        $filters = $this->buildHistoryFilters($request);
        $page = max(1, $request->query->getInt('page', 1));
        $limit = min(100, max(10, $request->query->getInt('limit', self::DEFAULT_PAGE_SIZE)));
        $offset = ($page - 1) * $limit;

        $logs = $this->alertLogRepository->findWithFilters($filters, $limit, $offset);
        $totalCount = $this->alertLogRepository->countWithFilters($filters);

        $totalPages = (int) ceil($totalCount / $limit);

        $stats = $this->alertLogRepository->countByStatus();

        return $this->render('dashboard/alerts/history.html.twig', [
            'logs' => $logs,
            'stats' => $stats,
            'filters' => [
                'channelType' => $request->query->get('channelType'),
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

    /**
     * @return array{channelType?: string, isActive?: bool, isGlobal?: bool}
     */
    private function buildFilters(Request $request): array
    {
        $filters = [];

        $channelType = $request->query->get('channelType');
        if ($channelType !== null && $channelType !== '') {
            $filters['channelType'] = $channelType;
        }

        $status = $request->query->get('status');
        if ($status === 'active') {
            $filters['isActive'] = true;
        } elseif ($status === 'inactive') {
            $filters['isActive'] = false;
        }

        $scope = $request->query->get('scope');
        if ($scope === 'global') {
            $filters['isGlobal'] = true;
        } elseif ($scope === 'token') {
            $filters['isGlobal'] = false;
        }

        return $filters;
    }

    /**
     * @return array{channelType?: string, status?: string}
     */
    private function buildHistoryFilters(Request $request): array
    {
        $filters = [];

        $channelType = $request->query->get('channelType');
        if ($channelType !== null && $channelType !== '') {
            $filters['channelType'] = $channelType;
        }

        $status = $request->query->get('status');
        if ($status !== null && $status !== '') {
            $filters['status'] = $status;
        }

        return $filters;
    }
}
