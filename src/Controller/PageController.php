<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Renders public-facing shell pages.
 */
class PageController extends AbstractController
{
    /**
     * Displays the home page.
     */
    #[Route('/', name: 'app_home')]
    public function home(): Response
    {
        return $this->render('home/index.html.twig', [
            'username' => $this->getUser()?->getUserIdentifier(),
        ]);
    }

    /**
     * Displays the login page.
     */
    #[Route('/login', name: 'app_login')]
    public function login(): Response
    {
        return $this->render('base/login.html.twig');
    }
}
