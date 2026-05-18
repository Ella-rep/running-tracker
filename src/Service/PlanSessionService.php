<?php

namespace App\Service;

use App\Entity\Plan;

class PlanSessionService
{
    /** @return array<int, array{sem:int, date:?string, format:string, pe:?string, totalMin:?int, isOptional:bool}> */
    public function getSessionsForPlan(Plan $plan): array
    {
        return $this->starterSessions();
    }

    /** @return array<int, array{sem:int, date:?string, format:string, pe:?string, totalMin:?int, isOptional:bool}> */
    private function starterSessions(): array
    {
        return [
            ['sem' => 1, 'date' => null, 'format' => "45'@Z2", 'pe' => '3/10', 'totalMin' => 45, 'isOptional' => false],
            ['sem' => 1, 'date' => null, 'format' => "20'@Z2 >> 10x (30\"@Z5 + 30\"@Z1) >> 5'@Z1", 'pe' => '4/10', 'totalMin' => 45, 'isOptional' => false],
            ['sem' => 1, 'date' => null, 'format' => "45'@Z2 >> 8x (20\"@Z5 + 40\"@Z1) >> 5'@Z1", 'pe' => '4/10', 'totalMin' => 58, 'isOptional' => true],
            ['sem' => 1, 'date' => null, 'format' => "90'@Z2", 'pe' => '4/10', 'totalMin' => 90, 'isOptional' => false],
        ];
    }
}
