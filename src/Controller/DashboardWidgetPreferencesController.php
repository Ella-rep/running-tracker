<?php

namespace App\Controller;

use App\Entity\DashboardWidgetKeys;
use App\Entity\User;
use App\Service\DashboardWidgetPreferenceService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class DashboardWidgetPreferencesController extends AbstractController
{
    #[Route('/api/user/preferences/dashboard-widgets', name: 'api_user_dashboard_widget_preferences_get', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getPreferences(DashboardWidgetPreferenceService $widgetPreferences): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Utilisateur non authentifie.');
        }

        return $this->json([
            'widgets' => $widgetPreferences->visibilityMap($user),
        ]);
    }

    #[Route('/api/user/preferences/dashboard-widgets', name: 'api_user_dashboard_widget_preferences', methods: ['PATCH'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(
        Request $request,
        EntityManagerInterface $entityManager,
        DashboardWidgetPreferenceService $widgetPreferences,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Utilisateur non authentifie.');
        }

        try {
            $data = json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json([
                'code' => 'invalid_payload',
                'message' => 'Payload JSON invalide.',
            ], 400);
        }

        $widgets = $data['widgets'] ?? null;
        if (!is_array($widgets)) {
            return $this->json([
                'code' => 'invalid_widgets',
                'message' => 'Le champ widgets (objet) est requis.',
            ], 400);
        }

        $allowedKeys = array_flip(DashboardWidgetKeys::all());
        foreach ($widgets as $key => $visible) {
            $widgetKey = trim((string) $key);
            if ($widgetKey === '' || !isset($allowedKeys[$widgetKey])) {
                continue;
            }
            $user->setDashboardWidgetVisible($widgetKey, (bool) $visible);
        }

        $entityManager->flush();

        return $this->json([
            'message' => 'Preferences widgets enregistrees.',
            'widgets' => $widgetPreferences->visibilityMap($user),
        ]);
    }
}
