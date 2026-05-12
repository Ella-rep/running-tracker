<?php

namespace App\Controller;

use App\Service\DashboardAdviceService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class DashboardAdviceController extends AbstractController
{
    #[Route('/api/dashboard/advice', name: 'api_dashboard_advice', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(DashboardAdviceService $service): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['items' => []], 401);
        }

        return $this->json([
            'items' => $service->build($user),
        ]);
    }
}
