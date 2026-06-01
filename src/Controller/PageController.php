<?php

namespace App\Controller;

use App\Service\MeteoService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
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

    /**
     * Renders branded 404 page for unknown browser routes.
     */
    #[Route(
        '/{path}',
        name: 'app_not_found',
        requirements: ['path' => '(?!api|_wdt|_profiler|_error).+'],
        methods: ['GET'],
        priority: -255
    )]
    public function notFound(Request $request): Response
    {
        if (!str_contains((string) $request->headers->get('Accept', ''), 'text/html')) {
            throw $this->createNotFoundException();
        }

        return $this->render(
            'bundles/TwigBundle/Exception/error404.html.twig',
            [],
            new Response('', Response::HTTP_NOT_FOUND)
        );
    }
}
