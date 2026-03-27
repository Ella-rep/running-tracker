<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class TrackerController extends AbstractController
{
    #[Route('/tracker', name: 'app_tracker')]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        return $this->render('base/tracker.html.twig', [
            'username' => $this->getUser()?->getUserIdentifier(),
        ]);
    }
}
