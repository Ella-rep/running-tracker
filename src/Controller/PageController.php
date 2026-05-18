<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PageController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function home(): Response
    {
        return $this->render('home/index.html.twig', [
            'username' => $this->getUser()?->getUserIdentifier(),
        ]);
    }

    /**
     * Login page
     */
    #[Route('/login', name: 'app_login')]
    public function login(): Response
    {
        return $this->render('base/login.html.twig');
    }

    /**
     * Backward compatibility: old SPA URL now redirects to dashboard
     */
    #[Route('/app', name: 'app_home_legacy')]
    public function appLegacy(): Response
    {
        return $this->redirectToRoute('app_dashboard');
    }

    /**
     * Backward compatibility: old tracker URL now redirects to dashboard
     */
    #[Route('/tracker', name: 'app_tracker_legacy')]
    public function trackerLegacy(): Response
    {
        return $this->redirectToRoute('app_dashboard');
    }
}
