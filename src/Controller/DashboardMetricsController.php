<?php

namespace App\Controller;

use App\Service\DashboardMetricsFacadeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Exposes dashboard metrics API endpoints.
 */
final class DashboardMetricsController extends AbstractController
{
    /**
     * Returns dashboard KPIs and widgets payload for the authenticated user.
     */
    #[Route('/api/dashboard/metrics', name: 'api_dashboard_metrics', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(DashboardMetricsFacadeService $service): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json([
                'kpis' => null,
                'monthlyBars' => [],
                'projections' => [],
                'projectionsMeta' => '',
                'efKpis' => ['items' => [], 'emptyMessage' => ''],
                'ef' => [
                    'hasData' => false,
                    'emptyMessage' => '',
                    'chart' => ['paceTicks' => [], 'bpmTicks' => [], 'pacePoints' => [], 'bpmPoints' => [], 'efDots' => []],
                    'tableRows' => [],
                    'meta' => '',
                ],
                'coherenceAlerts' => [],
                'racesTable' => [],
                'planProgress' => ['title' => 'Suivi des plans', 'focusTitle' => '', 'done' => 0, 'total' => 0, 'pct' => 0, 'plans' => []],
            ], 401);
        }

        return $this->json($service->build($user));
    }
}
