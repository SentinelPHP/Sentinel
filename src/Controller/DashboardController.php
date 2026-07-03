<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/dashboard')]
#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractController
{
    #[Route('', name: 'dashboard_index')]
    public function index(): Response
    {
        return $this->render('dashboard/index.html.twig');
    }

    #[Route('/logs', name: 'dashboard_logs')]
    public function logs(): Response
    {
        return $this->render('dashboard/placeholder.html.twig', [
            'title' => 'Request Logs',
        ]);
    }

    #[Route('/tokens', name: 'dashboard_tokens')]
    public function tokens(): Response
    {
        return $this->render('dashboard/placeholder.html.twig', [
            'title' => 'API Tokens',
        ]);
    }

    #[Route('/schemas', name: 'dashboard_schemas')]
    public function schemas(): Response
    {
        return $this->render('dashboard/placeholder.html.twig', [
            'title' => 'Schemas',
        ]);
    }

}
