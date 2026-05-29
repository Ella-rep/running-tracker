<?php

namespace App\Controller;

use App\Service\MeteoService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
    public function home(MeteoService $meteoService): Response
    {
        return $this->render('home/index.html.twig', [
            'username' => $this->getUser()?->getUserIdentifier(),
            'initialWeatherAdvice' => $meteoService->buildDailyAdvice(),
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
