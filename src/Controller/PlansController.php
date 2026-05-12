<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PlansController extends AbstractController
{
    #[Route('/plans', name: 'app_plans')]
    public function index(): Response
    {
        return $this->render('plans/index.html.twig', [
            'username' => $this->getUser()?->getUserIdentifier(),
        ]);
    }
}
