<?php

namespace App\Service;

use App\Entity\Plan;

/**
 * Provides canonical session templates for training plans.
 */
class PlanSessionService
{
    /**
     * Returns normalized sessions for the provided plan.
     *
     * @return array<int, array{sem:int, date:?string, format:string, sessionType:?string, pe:?string, totalMin:?int, isOptional:bool}>
     */
    public function getSessionsForPlan(Plan $plan): array
    {
        $planName = strtolower(trim((string) $plan->getName()));
        if ($planName === 'starter') {
            return $this->starterSessions();
        }

        return $this->starterSessions();
    }

    /** @return array<int, array{sem:int, date:?string, format:string, sessionType:?string, pe:?string, totalMin:?int, isOptional:bool}> */
    private function starterSessions(): array
    {
        return [
            ['sem' => 1, 'date' => null, 'format' => "45'@Z2", 'sessionType' => 'EF', 'pe' => '3/10', 'totalMin' => 45, 'isOptional' => false],
            ['sem' => 1, 'date' => null, 'format' => "20'@Z2 >> 10x (30\"@Z5 + 30\"@Z1) >> 5'@Z1", 'sessionType' => 'FC', 'pe' => '4/10', 'totalMin' => 45, 'isOptional' => false],
            ['sem' => 1, 'date' => null, 'format' => "45'@Z2 >> 8x (20\"@Z5 + 40\"@Z1) >> 5'@Z1", 'sessionType' => 'FC', 'pe' => '4/10', 'totalMin' => 58, 'isOptional' => true],
            ['sem' => 1, 'date' => null, 'format' => "90'@Z2", 'sessionType' => 'FL', 'pe' => '4/10', 'totalMin' => 90, 'isOptional' => false],
        ];
    }
}
