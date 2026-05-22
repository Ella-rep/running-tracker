<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Renders the run log page shell.
 */
class LogController extends AbstractController
{
    /**
     * Displays the log page.
     */
    #[Route('/log', name: 'app_log')]
    public function index(): Response
    {
        return $this->render('log/index.html.twig', [
            'username' => $this->getUser()?->getUserIdentifier(),
        ]);
    }
}
