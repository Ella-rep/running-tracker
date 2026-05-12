<?php

namespace App\Service;

use App\Entity\Plan;

class PlanSessionService
{
    /** @return array<int, array{sem:int, date:?string, format:string, pe:?string, totalMin:?int, isOptional:bool}> */
    public function getSessionsForPlan(Plan $plan): array
    {
        return $plan->getName() === 'semi'
            ? $this->semiSessions()
            : $this->starterSessions();
    }

    /** @return array<int, array{sem:int, date:?string, format:string, pe:?string, totalMin:?int, isOptional:bool}> */
    private function starterSessions(): array
    {
        return [
            ['sem' => 1, 'date' => null, 'format' => "45'@Z2", 'pe' => '3/10', 'totalMin' => 45, 'isOptional' => false],
            ['sem' => 1, 'date' => null, 'format' => "45'@Z2", 'pe' => '3/10', 'totalMin' => 45, 'isOptional' => false],
            ['sem' => 1, 'date' => null, 'format' => "45'@Z2", 'pe' => '3/10', 'totalMin' => 45, 'isOptional' => false],
            ['sem' => 1, 'date' => null, 'format' => "60'@Z2", 'pe' => '4/10', 'totalMin' => 60, 'isOptional' => false],
        ];
    }

    /** @return array<int, array{sem:int, date:?string, format:string, pe:?string, totalMin:?int, isOptional:bool}> */
    private function semiSessions(): array
    {
        return [
            ['sem' => 1, 'date' => null, 'format' => "45'@Z2", 'pe' => '3/10', 'totalMin' => 45, 'isOptional' => false],
            ['sem' => 1, 'date' => null, 'format' => "20'@Z2 >> 10x (30\"@Z5 + 30\"@Z1) >> 5'@Z1", 'pe' => '4/10', 'totalMin' => 45, 'isOptional' => false],
            ['sem' => 1, 'date' => null, 'format' => "30'@Z2", 'pe' => '3/10', 'totalMin' => 30, 'isOptional' => false],
            ['sem' => 1, 'date' => null, 'format' => "15'@Z2 >> 15'@Z3 + 15'@Z4 >> 15'@Z2", 'pe' => '5/10', 'totalMin' => 60, 'isOptional' => false],
            ['sem' => 2, 'date' => null, 'format' => "45'@Z2", 'pe' => '3/10', 'totalMin' => 45, 'isOptional' => false],
            ['sem' => 2, 'date' => null, 'format' => "20'@Z2 >> 8x (1'@Z5 + 1'@Z1) >> 5'@Z1", 'pe' => '5/10', 'totalMin' => 41, 'isOptional' => false],
            ['sem' => 2, 'date' => null, 'format' => "35'@Z2", 'pe' => '3/10', 'totalMin' => 35, 'isOptional' => false],
            ['sem' => 2, 'date' => null, 'format' => "25'@Z2 >> 15'@Z4 >> 25'@Z2", 'pe' => '5/10', 'totalMin' => 65, 'isOptional' => false],
            ['sem' => 3, 'date' => null, 'format' => "45'@Z2 >> 8x (20\"@Z5 + 40\"@Z1) >> 5'@Z1", 'pe' => '4/10', 'totalMin' => 58, 'isOptional' => false],
            ['sem' => 3, 'date' => null, 'format' => "20'@Z2 >> 8x (1'@Z5 + 1'@Z1) >> 15'@Z2", 'pe' => '5/10', 'totalMin' => 51, 'isOptional' => false],
            ['sem' => 3, 'date' => null, 'format' => "40'@Z2", 'pe' => '3/10', 'totalMin' => 40, 'isOptional' => false],
            ['sem' => 3, 'date' => null, 'format' => "70'@Z2", 'pe' => '4/10', 'totalMin' => 70, 'isOptional' => false],
            ['sem' => 4, 'date' => null, 'format' => "25'@Z2 >> 5x (2'@Z5 + 2'@Z1) >> 5'@Z1", 'pe' => '5/10', 'totalMin' => 50, 'isOptional' => false],
            ['sem' => 4, 'date' => null, 'format' => "20'@Z2 >> 3x (8'@Z4 + 3'@Z1) >> 5'@Z1", 'pe' => '6/10', 'totalMin' => 58, 'isOptional' => false],
            ['sem' => 4, 'date' => null, 'format' => "75'@Z2", 'pe' => '4/10', 'totalMin' => 75, 'isOptional' => false],
            ['sem' => 5, 'date' => null, 'format' => "20'@Z2 >> 10x (3'@Z4 + 1'30\"@Z1) >> 5'@Z1", 'pe' => '6/10', 'totalMin' => 60, 'isOptional' => false],
            ['sem' => 5, 'date' => null, 'format' => "45'@Z2", 'pe' => '3/10', 'totalMin' => 45, 'isOptional' => false],
            ['sem' => 5, 'date' => null, 'format' => "20'@Z2 >> 2x (10'@Z3 + 3'@Z1) >> 5'@Z1", 'pe' => '4/10', 'totalMin' => 51, 'isOptional' => false],
            ['sem' => 5, 'date' => null, 'format' => "80'@Z2", 'pe' => '4/10', 'totalMin' => 80, 'isOptional' => false],
            ['sem' => 6, 'date' => null, 'format' => "25'@Z2 >> 10x (2'@Z5 + 1'@Z1) >> 5'@Z1", 'pe' => '6/10', 'totalMin' => 60, 'isOptional' => false],
            ['sem' => 6, 'date' => null, 'format' => "20'@Z2 >> 4x (7'@Z4 + 3'@Z1) >> 5'@Z1", 'pe' => '6/10', 'totalMin' => 65, 'isOptional' => false],
            ['sem' => 6, 'date' => null, 'format' => "85'@Z2", 'pe' => '4/10', 'totalMin' => 85, 'isOptional' => false],
            ['sem' => 7, 'date' => null, 'format' => "25'@Z2 >> 8x (2'30\"@Z5 + 2'@Z1) >> 5'@Z1", 'pe' => '6/10', 'totalMin' => 66, 'isOptional' => false],
            ['sem' => 7, 'date' => null, 'format' => "25'@Z2 >> 20'@Z3 >> 5'@Z1", 'pe' => '4/10', 'totalMin' => 50, 'isOptional' => false],
            ['sem' => 7, 'date' => null, 'format' => "90'@Z2", 'pe' => '4/10', 'totalMin' => 90, 'isOptional' => false],
            ['sem' => 8, 'date' => null, 'format' => "25'@Z2 >> 6x (2'@Z5 + 1'@Z1) >> 15'@Z2", 'pe' => '5/10', 'totalMin' => 58, 'isOptional' => false],
            ['sem' => 8, 'date' => null, 'format' => "20'@Z2 >> 3x (10'@Z4 + 3'@Z1) >> 5'@Z1", 'pe' => '6/10', 'totalMin' => 66, 'isOptional' => false],
            ['sem' => 8, 'date' => null, 'format' => "1h35@Z2", 'pe' => '4/10', 'totalMin' => 95, 'isOptional' => false],
            ['sem' => 9, 'date' => null, 'format' => "25'@Z2 >> 5x (5'@Z5 + 2'@Z1) >> 5'@Z1", 'pe' => '6/10', 'totalMin' => 65, 'isOptional' => false],
            ['sem' => 9, 'date' => null, 'format' => "25'@Z2 >> 2x (15'@Z4 + 3'@Z1) >> 5'@Z1", 'pe' => '6/10', 'totalMin' => 66, 'isOptional' => false],
            ['sem' => 9, 'date' => null, 'format' => "90'@Z2", 'pe' => '4/10', 'totalMin' => 90, 'isOptional' => false],
            ['sem' => 10, 'date' => null, 'format' => "@Z3 + 1'@Z1 + 4x(2'@Z4 + 1'@Z5 + 1'@Z1 + 2'@Z2) + 5'@Z1", 'pe' => '7/10', 'totalMin' => 65, 'isOptional' => false],
            ['sem' => 10, 'date' => null, 'format' => "25'@Z2 >> 20'@Z3 >> 5'@Z1", 'pe' => '4/10', 'totalMin' => 50, 'isOptional' => false],
            ['sem' => 10, 'date' => null, 'format' => "45'@Z2", 'pe' => '3/10', 'totalMin' => 45, 'isOptional' => false],
            ['sem' => 11, 'date' => null, 'format' => "85'@Z2", 'pe' => '4/10', 'totalMin' => 85, 'isOptional' => false],
            ['sem' => 11, 'date' => null, 'format' => "25'@Z2 >> 8x (3'@Z4 + 1'30\"@Z1) >> 5'@Z1", 'pe' => '5/10', 'totalMin' => 66, 'isOptional' => false],
            ['sem' => 11, 'date' => null, 'format' => "40'@Z2", 'pe' => '3/10', 'totalMin' => 40, 'isOptional' => false],
            ['sem' => 12, 'date' => null, 'format' => "20'@Z2 >> 5x (30\"@Z5 + 1'30\"@Z2) >> 5'@Z1", 'pe' => '6/10', 'totalMin' => 45, 'isOptional' => false],
            ['sem' => 12, 'date' => null, 'format' => "35'@Z2", 'pe' => '3/10', 'totalMin' => 35, 'isOptional' => false],
            ['sem' => 12, 'date' => null, 'format' => "4 JOUR J !!", 'pe' => null, 'totalMin' => null, 'isOptional' => false],
        ];
    }
}