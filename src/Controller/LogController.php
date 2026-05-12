<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LogController extends AbstractController
{
    #[Route('/log', name: 'app_log')]
    public function index(): Response
    {
        return $this->render('log/index.html.twig', [
            'username' => $this->getUser()?->getUserIdentifier(),
        ]);
    }
}
