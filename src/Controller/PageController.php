<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class PageController extends AbstractController
{
    /**
     * Login page — redirects to app if already logged in
     */
    #[Route('/', name: 'app_login')]
    public function login(): Response
    {
        return $this->render('base/login.html.twig');
    }

    /**
     * Main tracker app — requires auth
     */
    #[Route('/tracker', name: 'app_tracker')]
    #[IsGranted('ROLE_USER')]
    public function tracker(): Response
    {
        return $this->render('base/tracker.html.twig', [
            'username' => $this->getUser()?->getUserIdentifier(),
        ]);
    }

    /**
     * Main SPA shell — requires auth (JS handles the rest via API)
     */
    #[Route('/app', name: 'app_home')]
    public function app(): Response
    {
        return $this->render('base/app.html.twig', [
            'username' => $this->getUser()?->getUserIdentifier(),
        ]);
    }

    /**
     * Catch-all for client-side routing under /app/*
     */
    #[Route('/app/{path}', name: 'app_spa', requirements: ['path' => '.+'])]
    public function spa(): Response
    {
        return $this->render('base/app.html.twig', [
            'username' => $this->getUser()?->getUserIdentifier(),
        ]);
    }
}
