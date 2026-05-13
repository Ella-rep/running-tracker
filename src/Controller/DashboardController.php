<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'app_dashboard')]
    #[Route('/app/dashboard', name: 'app_dashboard_alt')]
    public function dashboard(): Response
    {
        return $this->render('dashboard/index.html.twig', [
            'username' => $this->getUser()?->getUserIdentifier(),
        ]);
    }
}
