<?php

namespace App\Service;

use App\Entity\DashboardWidgetKeys;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Handles dashboard widget preference definitions and persistence.
 */
class DashboardWidgetPreferenceService
{
    /**
     * @param EntityManagerInterface $entityManager Entity manager used to persist user preferences.
     */
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * Returns dashboard widget definitions displayed in settings UI.
     *
     * @return list<array{key: string, label: string, description: string}>
     */
    public function definitions(): array
    {
        return [
            [
                'key' => DashboardWidgetKeys::PROJECTIONS,
                'label' => 'Temps projetes',
                'description' => 'Estimations de chrono basees sur les dernieres sorties.',
            ],
            [
                'key' => DashboardWidgetKeys::EF_BPM,
                'label' => 'Suivi BPM EF',
                'description' => 'Evolution de la frequence cardiaque en endurance fondamentale.',
            ],
            [
                'key' => DashboardWidgetKeys::TRAINING_LOAD,
                'label' => 'Charge entrainement',
                'description' => 'Ratio de charge recente vs base de reference.',
            ],
            [
                'key' => DashboardWidgetKeys::PLAN_PROGRESS,
                'label' => 'Suivi des plans',
                'description' => 'Etat du plan le plus avance et resume de tous les plans.',
            ],
            [
                'key' => DashboardWidgetKeys::MONTHLY_LOAD,
                'label' => 'Kilometrage mensuel',
                'description' => 'Volume mensuel de course a pied.',
            ],
            [
                'key' => DashboardWidgetKeys::COHERENCE,
                'label' => 'Alertes coherence',
                'description' => 'Points d attention entre objectifs et charge.',
            ],
            [
                'key' => DashboardWidgetKeys::RACE_AVG,
                'label' => 'Allure moyenne course',
                'description' => 'Allure moyenne sur tes courses officielles terminees.',
            ],
            [
                'key' => DashboardWidgetKeys::GAMIFICATION,
                'label' => 'Mode RPG ⚔️',
                'description' => 'Quetes, XP, skills et modificateurs. Pour les runners geeks.',
            ],
        ];
    }

    /**
     * Builds effective visibility map by applying defaults then user overrides.
     *
     * @return array<string, bool>
     */
    public function visibilityMap(User $user): array
    {
        $map = [];
        foreach (DashboardWidgetKeys::all() as $key) {
            $map[$key] = $key === DashboardWidgetKeys::MONTHLY_LOAD;
        }

        foreach ($user->getDashboardWidgetVisibilityMap() as $key => $visible) {
            if (array_key_exists($key, $map)) {
                $map[$key] = (bool) $visible;
            }
        }

        return $map;
    }

    /**
     * Applies the provided widget visibility map and persists the updated user preferences.
     *
     * @param array<string,mixed> $widgets
     */
    public function applyVisibilityUpdates(User $user, array $widgets): void
    {
        $allowedKeys = array_flip(DashboardWidgetKeys::all());

        foreach ($widgets as $key => $visible) {
            $widgetKey = trim((string) $key);
            if ($widgetKey === '' || !isset($allowedKeys[$widgetKey])) {
                continue;
            }

            // Cast any truthy/falsy payload value into a strict boolean preference flag.
            $user->setDashboardWidgetVisible($widgetKey, (bool) $visible);
        }

        $this->entityManager->flush();
    }
}
