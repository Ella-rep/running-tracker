<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\DashboardWidgetPreferenceService;
use App\Service\GamificationWidgetService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Renders the dedicated dashboard page shell.
 */
class DashboardController extends AbstractController
{
    /**
     * Displays the dashboard page with widget definitions and visibility state.
     */
    #[Route('/dashboard', name: 'app_dashboard')]
    #[Route('/app/dashboard', name: 'app_dashboard_alt')]
    public function dashboard(
        DashboardWidgetPreferenceService $widgetPreferences,
        GamificationWidgetService $gamification,
    ): Response {
        $user = $this->getUser();
        $visibilityMap = $user instanceof User
            ? $widgetPreferences->visibilityMap($user)
            : [];

        $rpgData = null;
        if ($user instanceof User && ($visibilityMap['gamification'] ?? false)) {
            $rpgData = $gamification->buildWidgetData($user);
        }

        return $this->render('dashboard/index.html.twig', [
            'username'                     => $user?->getUserIdentifier(),
            'dashboard_widget_definitions' => $widgetPreferences->definitions(),
            'dashboard_widget_state'       => $visibilityMap,
            'rpg_data'                     => $rpgData,
        ]);
    }
}
