<?php

namespace App\Service;

use App\Entity\DashboardWidgetKeys;
use App\Entity\User;

class DashboardWidgetPreferenceService
{
    /** @return list<array{key: string, label: string, description: string}> */
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
                'label' => 'Progression du plan',
                'description' => 'Avancement global du plan actif.',
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
        ];
    }

    /** @return array<string, bool> */
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
}
