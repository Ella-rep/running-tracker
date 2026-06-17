<?php

namespace App\Service;

use App\Entity\Race;
use App\Entity\RunLog;
use App\Entity\User;
use App\Repository\RaceRepository;
use App\Repository\RunLogRepository;

/**
 * Builds the weekly running digest payload for a user (stats + charge + race + advice).
 * Used by the weekly email command.
 */
final class WeeklySummaryService
{
    public function __construct(
        private RunLogRepository $runLogRepository,
        private RaceRepository $raceRepository,
        private DashboardAdvancedMetricsService $advancedMetrics,
    ) {
    }

    /**
     * Stats over the last 7 days (today included).
     * @return array{runs:int, km:float, duration:string, durationSec:int}
     */
    public function getWeekStats(User $user, ?\DateTimeImmutable $now = null): array
    {
        $today = ($now ?? new \DateTimeImmutable('now'))->setTime(0, 0, 0);
        $from = $today->modify('-6 days');
        $logs = $this->runLogRepository->findByUserAndDateRange(
            $user,
            $from->format('Y-m-d'),
            $today->format('Y-m-d'),
        );

        $km = 0.0;
        $sec = 0;
        foreach ($logs as $log) {
            $km += (float) ($log->getKm() ?? 0.0);
            $d = $this->durationToSeconds($log->getDuration());
            if ($d !== null) {
                $sec += $d;
            }
        }

        return [
            'runs' => count($logs),
            'km' => round($km, 1),
            'duration' => $this->secondsToHm($sec),
            'durationSec' => $sec,
        ];
    }

    /**
     * Full digest payload. Returns null when there is nothing to send
     * (no runs this week and user not on health pause).
     *
     * @return array{
     *   prenom:string, runs:int, km:float, duration:string,
     *   chargePct:?int, race:?array{name:string, days:int}, conseil:string, pause:bool
     * }|null
     */
    public function buildSummary(User $user, ?\DateTimeImmutable $now = null): ?array
    {
        $today = ($now ?? new \DateTimeImmutable('now'))->setTime(0, 0, 0);
        $pause = $user->isOnHealthPause();
        $stats = $this->getWeekStats($user, $today);

        if ($stats['runs'] === 0 && !$pause) {
            return null;
        }

        $allLogs = $user->getRunLogs()->toArray();
        $ratio = $this->advancedMetrics->getChargeRatio($allLogs);
        $chargePct = $ratio !== null ? (int) round($ratio * 100) : null;

        $race = $this->raceRepository->findNextRace($user, $today->format('Y-m-d'));
        $raceData = null;
        $daysToRace = null;
        if ($race instanceof Race) {
            $raceDate = \DateTimeImmutable::createFromFormat('Y-m-d', $race->getDate());
            if ($raceDate instanceof \DateTimeImmutable) {
                $daysToRace = (int) $today->diff($raceDate->setTime(0, 0, 0))->format('%r%a');
            }
            $raceData = ['name' => $race->getName(), 'days' => $daysToRace ?? 0];
        }

        return [
            'prenom' => $user->getUsername(),
            'runs' => $stats['runs'],
            'km' => $stats['km'],
            'duration' => $stats['duration'],
            'chargePct' => $chargePct,
            'race' => $raceData,
            'conseil' => $this->genererConseil($chargePct, $daysToRace, $pause),
            'pause' => $pause,
        ];
    }

    private function genererConseil(?int $chargePct, ?int $daysToRace, bool $pause): string
    {
        if ($pause) {
            return 'Tu es en pause. Repose-toi, on reprendra en douceur.';
        }
        if ($chargePct !== null && $daysToRace !== null && $daysToRace <= 14 && $chargePct > 115) {
            return 'Course proche et charge élevée → commence à réduire le volume.';
        }
        if ($chargePct !== null && $chargePct > 130) {
            return 'Charge trop haute → 24-48h de récup avant d\'enchaîner.';
        }
        if ($chargePct !== null && $chargePct >= 80 && $chargePct <= 115) {
            return 'Charge bien équilibrée → garde le rythme.';
        }
        if ($chargePct !== null && $chargePct < 80) {
            return 'Marge dispo → tu peux ajouter une sortie.';
        }
        return 'Continue régulièrement pour stabiliser ta base.';
    }

    private function durationToSeconds(?string $duration): ?int
    {
        if ($duration === null || !str_contains($duration, ':')) {
            return null;
        }
        $parts = array_map('intval', explode(':', $duration));
        if (count($parts) === 3) {
            return $parts[0] * 3600 + $parts[1] * 60 + $parts[2];
        }
        if (count($parts) === 2) {
            return $parts[0] * 60 + $parts[1];
        }
        return null;
    }

    private function secondsToHm(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        if ($h > 0) {
            return sprintf('%dh%02d', $h, $m);
        }
        return sprintf('%dmin', $m);
    }
}
