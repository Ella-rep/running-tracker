<?php

namespace App\Controller;

use App\Service\DashboardAdviceService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Exposes contextual dashboard advice API endpoint.
 */
final class DashboardAdviceController extends AbstractController
{
    /**
     * Returns personalized advice items for the authenticated user.
     */
    #[Route('/api/dashboard/advice', name: 'api_dashboard_advice', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(DashboardAdviceService $service, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['items' => []], 401);
        }

        $city = trim((string) $request->query->get('city', ''));
        if ($city === '') {
            $city = null;
        }

        return $this->json([
            'items' => $service->build($user, $city),
        ]);
    }
}
