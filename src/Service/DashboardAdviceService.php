<?php

namespace App\Service;

use App\Entity\Race;
use App\Entity\RunLog;
use App\Entity\User;
use App\Repository\RaceRepository;
use App\Repository\RunLogRepository;

final class DashboardAdviceService
{
    public function __construct(
        private RunLogRepository $runLogs,
        private RaceRepository $races,
    ) {}

    /**
     * @return array<int, array{title:string,text:string,tone:string,icon:string,color:string,badge:string}>
     */
    public function build(User $user): array
    {
        $today = new \DateTimeImmutable('today');
        $todayStr = $today->format('Y-m-d');
        $yesterdayStr = $today->sub(new \DateInterval('P1D'))->format('Y-m-d');
        $weekStartStr = $today->sub(new \DateInterval('P7D'))->format('Y-m-d');

        /** @var array<int, RunLog> $logs */
        $logs = $this->runLogs->findBy(['user' => $user], ['date' => 'DESC'], 200);
        /** @var array<int, Race> $races */
        $races = $this->races->findBy(['user' => $user], ['date' => 'ASC'], 50);

        $todayRun = null;
        $yesterdayRun = null;
        $latestRun = $logs[0] ?? null;

        $weekKm = 0.0;
        $weekCount = 0;

        foreach ($logs as $log) {
            $date = $log->getDate();
            if ($date === $todayStr && $todayRun === null) {
                $todayRun = $log;
            }
            if ($date === $yesterdayStr && $yesterdayRun === null) {
                $yesterdayRun = $log;
            }
            if ($date >= $weekStartStr && $date <= $todayStr) {
                $weekKm += (float) ($log->getKm() ?? 0.0);
                $weekCount++;
            }
        }

        $daysSince = 99;
        if ($latestRun !== null) {
            $latestDate = \DateTimeImmutable::createFromFormat('Y-m-d', $latestRun->getDate());
            if ($latestDate instanceof \DateTimeImmutable) {
                $daysSince = (int) $latestDate->diff($today)->format('%a');
            }
        }

        $nextRace = null;
        $nextRaceDays = null;
        foreach ($races as $race) {
            $result = trim((string) ($race->getResult() ?? ''));
            if ($result !== '') {
                continue;
            }

            $raceDate = \DateTimeImmutable::createFromFormat('Y-m-d', $race->getDate());
            if (!$raceDate instanceof \DateTimeImmutable) {
                continue;
            }

            $daysTo = (int) $today->diff($raceDate)->format('%r%a');
            if ($daysTo < 0) {
                continue;
            }

            $nextRace = $race;
            $nextRaceDays = $daysTo;
            break;
        }

        if ($nextRace !== null && $nextRaceDays === 0) {
            $dist = $nextRace->getDistance() ?: 'ta course';
            return [[
                'title' => 'Jour de course !',
                'text' => sprintf('C\'est le jour de %s (%s). Echauffement leger 15 min, reste bien hydrate, et bonne course !', $nextRace->getName(), $dist),
                'tone' => 'warning',
                'icon' => '🏁',
                'color' => '#e05580',
                'badge' => $nextRace->getName(),
            ]];
        }

        if ($nextRace !== null && $nextRaceDays === 1) {
            $dist = $nextRace->getDistance() ?: 'ta course';
            return [[
                'title' => 'Course demain - repos absolu',
                'text' => sprintf('La %s (%s) est demain. Aucune sortie aujourd\'hui: jambes au repos, bonne hydratation, dors tot.', $nextRace->getName(), $dist),
                'tone' => 'info',
                'icon' => '😴',
                'color' => '#8b9cf4',
                'badge' => $nextRace->getName() . ' J-1',
            ]];
        }

        if ($nextRace !== null && $nextRaceDays === 2) {
            $dist = $nextRace->getDistance() ?: 'ta course';
            return [[
                'title' => 'Activation legere J-2',
                'text' => sprintf('J-2 avant la %s (%s). Si tu sors: 20-30 min tres facile (< 9:00/km) pour activer les jambes.', $nextRace->getName(), $dist),
                'tone' => 'info',
                'icon' => '🧘',
                'color' => '#8b9cf4',
                'badge' => $nextRace->getName() . ' J-2',
            ]];
        }

        if ($nextRace !== null && $nextRaceDays !== null && $nextRaceDays <= 6) {
            $dist = $nextRace->getDistance() ?: 'ta course';
            $when = $nextRaceDays === 3 ? 'apres-demain' : ('dans ' . $nextRaceDays . ' jours');
            return [[
                'title' => 'Course proche - charge reduite',
                'text' => sprintf('La %s (%s) arrive %s. Reduis le volume, garde quelques accelerations courtes, evite la longue.', $nextRace->getName(), $dist, $when),
                'tone' => 'warning',
                'icon' => '⚡',
                'color' => '#f0c040',
                'badge' => $nextRace->getName(),
            ]];
        }

        if ($todayRun !== null) {
            $km = $todayRun->getKm() !== null ? number_format((float) $todayRun->getKm(), 1, '.', '') : '?';
            $allure = $todayRun->getAllure() ?: '--:--';
            return [[
                'title' => 'Seance effectuee',
                'text' => sprintf('%s km a %s/km. Bien recuperer: hydratation et etirements.', $km, $allure),
                'tone' => 'success',
                'icon' => '✅',
                'color' => '#4ade80',
                'badge' => '',
            ]];
        }

        if ($daysSince === 1 && $this->isIntenseRun($yesterdayRun)) {
            $km = $yesterdayRun?->getKm() !== null ? number_format((float) $yesterdayRun->getKm(), 1, '.', '') : '?';
            $allure = $yesterdayRun?->getAllure() ?: '--:--';
            $type = $yesterdayRun?->getRunType();
            $typeSuffix = $type ? ' (' . $type . ')' : '';
            return [[
                'title' => 'Recuperation conseillee',
                'text' => sprintf('Hier: %s km a %s/km%s. Laisse tes muscles recuperer aujourd\'hui.', $km, $allure, $typeSuffix),
                'tone' => 'info',
                'icon' => '🛌',
                'color' => '#8b9cf4',
                'badge' => 'J-1 intense',
            ]];
        }

        if ($daysSince >= 3) {
            $lastKm = $latestRun?->getKm() !== null ? number_format((float) $latestRun->getKm(), 1, '.', '') : '?';
            $lastDate = $latestRun?->getDate() ?: 'inconnue';
            return [[
                'title' => 'Il est temps de sortir !',
                'text' => sprintf('Derniere sortie il y a %d jours (%s km le %s). Le corps est repose: une EF 45-60 min est ideale.', $daysSince, $lastKm, $lastDate),
                'tone' => 'encourage',
                'icon' => '🚀',
                'color' => '#4ade80',
                'badge' => $daysSince . ' jours sans sortie',
            ]];
        }

        if ($daysSince === 1 && !$this->isIntenseRun($yesterdayRun) && $weekKm < 35) {
            $km = $yesterdayRun?->getKm() !== null ? number_format((float) $yesterdayRun->getKm(), 1, '.', '') : '?';
            $allure = $yesterdayRun?->getAllure() ?: '--:--';
            return [[
                'title' => 'Bonne journee pour courir',
                'text' => sprintf('Hier: %s km a %s/km (recuperation OK). Tu peux enchainer: EF ou tempo selon ton plan.', $km, $allure),
                'tone' => 'encourage',
                'icon' => '👟',
                'color' => '#4ade80',
                'badge' => 'J-1 facile',
            ]];
        }

        if ($weekKm >= 35) {
            return [[
                'title' => 'Charge elevee cette semaine',
                'text' => sprintf('%.1f km en %d sorties cette semaine. Bonne charge: ecoute ton corps et prends du repos si besoin.', $weekKm, $weekCount),
                'tone' => 'warning',
                'icon' => '⚠️',
                'color' => '#f0c040',
                'badge' => number_format($weekKm, 0, '.', '') . ' km / 7j',
            ]];
        }

        $text = $weekKm > 0
            ? sprintf('%.1f km en %d sortie%s cette semaine. Continue sur ta lancee !', $weekKm, $weekCount, $weekCount > 1 ? 's' : '')
            : 'Aucune sortie cette semaine: c\'est le bon moment pour demarrer !';

        return [[
            'title' => 'Plan du jour',
            'text' => $text,
            'tone' => 'info',
            'icon' => '📅',
            'color' => '#8b9cf4',
            'badge' => '',
        ]];
    }

    private function isIntenseRun(?RunLog $log): bool
    {
        if (!$log) {
            return false;
        }

        $type = strtoupper((string) ($log->getRunType() ?? ''));
        if (in_array($type, ['RACE', 'FC', 'FL', 'TEMPO', 'T'], true)) {
            return true;
        }

        $allureSeconds = $this->paceToSeconds($log->getAllure());
        return $allureSeconds !== null && $allureSeconds < 480;
    }

    private function paceToSeconds(?string $pace): ?int
    {
        if (!$pace || !str_contains($pace, ':')) {
            return null;
        }

        $parts = explode(':', $pace);
        if (count($parts) !== 2) {
            return null;
        }

        $m = (int) $parts[0];
        $s = (int) $parts[1];

        if ($m < 0 || $s < 0 || $s >= 60) {
            return null;
        }

        return $m * 60 + $s;
    }
}
