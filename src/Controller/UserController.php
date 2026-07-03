<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use App\Repository\UserRepository;
use App\Repository\UserTokenAccessRepository;
use App\Service\AccessControl\AccessControlServiceInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/dashboard/users')]
#[IsGranted('ROLE_ADMIN')]
final class UserController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly ApiTokenRepository $apiTokenRepository,
        private readonly UserTokenAccessRepository $userTokenAccessRepository,
        private readonly AccessControlServiceInterface $accessControlService,
    ) {
    }

    #[Route('', name: 'dashboard_users', methods: ['GET'])]
    public function index(): Response
    {
        $users = $this->userRepository->findBy([], ['email' => 'ASC']);

        return $this->render('dashboard/users/index.html.twig', [
            'users' => $users,
        ]);
    }

    #[Route('/{id}', name: 'dashboard_users_show', methods: ['GET'])]
    public function show(User $user): Response
    {
        $tokenAccess = $this->userTokenAccessRepository->findByUser($user);

        return $this->render('dashboard/users/show.html.twig', [
            'user' => $user,
            'tokenAccess' => $tokenAccess,
        ]);
    }

    #[Route('/{id}/permissions', name: 'dashboard_users_permissions', methods: ['GET', 'POST'])]
    public function permissions(Request $request, User $user): Response
    {
        if ($user->isAdmin()) {
            $this->addFlash('warning', 'Admin users have access to all tokens by default.');

            return $this->redirectToRoute('dashboard_users_show', ['id' => $user->getId()]);
        }

        $allTokens = $this->apiTokenRepository->findBy([], ['name' => 'ASC']);
        $currentAccess = $this->userTokenAccessRepository->findByUser($user);
        $currentTokenIds = array_map(
            fn ($access) => $access->getToken()->getId()->toRfc4122(),
            $currentAccess
        );

        if ($request->isMethod('POST')) {
            $submittedTokenIds = $request->request->all('tokens');

            // Revoke access for tokens no longer selected
            foreach ($currentAccess as $access) {
                $tokenId = $access->getToken()->getId()->toRfc4122();
                if (!in_array($tokenId, $submittedTokenIds, true)) {
                    $this->accessControlService->revokeAccess($user, $access->getToken());
                }
            }

            // Grant access for newly selected tokens
            foreach ($submittedTokenIds as $tokenId) {
                if (!in_array($tokenId, $currentTokenIds, true)) {
                    $token = $this->apiTokenRepository->find($tokenId);
                    if ($token instanceof ApiToken) {
                        $this->accessControlService->grantAccess($user, $token);
                    }
                }
            }

            $this->addFlash('success', 'User permissions updated successfully.');

            return $this->redirectToRoute('dashboard_users_show', ['id' => $user->getId()]);
        }

        return $this->render('dashboard/users/permissions.html.twig', [
            'user' => $user,
            'allTokens' => $allTokens,
            'currentTokenIds' => $currentTokenIds,
        ]);
    }
}
