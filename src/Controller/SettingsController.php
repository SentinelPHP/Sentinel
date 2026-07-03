<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\UserPreferencesType;
use App\Service\UserPreferencesServiceInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/dashboard/settings')]
#[IsGranted('ROLE_USER')]
class SettingsController extends AbstractController
{
    public function __construct(
        private readonly UserPreferencesServiceInterface $preferencesService,
    ) {
    }

    #[Route('', name: 'dashboard_settings', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        #[CurrentUser] User $user,
    ): Response {
        $preferences = $this->preferencesService->getPreferences($user);

        $form = $this->createForm(UserPreferencesType::class, $preferences);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->preferencesService->savePreferences($preferences);

            $this->addFlash('success', 'Settings saved successfully.');

            return $this->redirectToRoute('dashboard_settings');
        }

        return $this->render('dashboard/settings/index.html.twig', [
            'form' => $form,
            'preferences' => $preferences,
        ]);
    }
}
