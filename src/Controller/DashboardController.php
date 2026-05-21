<?php

namespace App\Controller;

use App\Service\DashboardWidgetPreferenceService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'app_dashboard')]
    #[Route('/app/dashboard', name: 'app_dashboard_alt')]
    public function dashboard(DashboardWidgetPreferenceService $widgetPreferences): Response
    {
        $user = $this->getUser();
        return $this->render('dashboard/index.html.twig', [
            'username' => $user?->getUserIdentifier(),
            'dashboard_widget_definitions' => $widgetPreferences->definitions(),
            'dashboard_widget_state' => $user instanceof \App\Entity\User
                ? $widgetPreferences->visibilityMap($user)
                : [],
        ]);
    }
}
