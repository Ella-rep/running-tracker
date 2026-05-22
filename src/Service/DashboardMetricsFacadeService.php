<?php

namespace App\Service;

use App\Entity\Race;
use App\Entity\RunLog;
use App\Entity\User;
use App\Repository\RaceRepository;
use App\Repository\RunLogRepository;

/**
 * Builds dashboard metric aggregates from training logs and races.
 */
final class DashboardMetricsFacadeService
{
    public function __construct(
        private RunLogRepository $runLogs,
        private RaceRepository $races,
        private DashboardAdvancedMetricsService $advancedMetrics,
        private DashboardEfMetricsService $efMetrics,
    ) {
    }

    /**
     * Builds the full dashboard payload used by frontend widgets.
     *
     * @return array<string,mixed>
     */
    public function build(User $user): array
    {
        $logs = $this->runLogs->findBy(['user' => $user], ['date' => 'DESC'], 500);

        [$projections, $projectionsMeta] = $this->buildProjections($logs);
        $planWidgets = $this->advancedMetrics->buildPlanWidgets($user);

        return [
            'kpis' => [
                'avgAllure' => $this->computeAverageAllure($logs),
                'longestDuration' => $this->computeLongestDuration($logs),
                'longestDistance' => $this->computeLongestDistance($logs),
                'avgBpm' => $this->computeAverageBpm($logs),
            ],
            'monthlyBars' => $this->buildMonthlyBars($logs),
            'projections' => $projections,
            'projectionsMeta' => $projectionsMeta,
            'trainingLoad' => $this->advancedMetrics->buildTrainingLoad($logs),
            'efKpis' => $this->efMetrics->buildEfKpis($logs),
            'ef' => $this->efMetrics->buildEfSection($logs),
            'coherenceAlerts' => $this->advancedMetrics->buildCoherenceAlerts($logs),
            'racesTable' => $this->buildDashboardRacesTable($user),
            'planProgress' => $planWidgets['progress'],
            'planCalendar' => $planWidgets['calendar'],
        ];
    }

    /**
     * @return array<int, array{statusClass:string,statusLabel:string,name:string,date:string,dist:string,obj:string,real:string}>
     */
    private function buildDashboardRacesTable(User $user): array
    {
        $races = $this->races->findBy(['user' => $user], ['date' => 'ASC']);
        $today = (new \DateTimeImmutable('now'))->setTime(0, 0, 0);

        $rows = [];
        foreach ($races as $race) {
            $days = $this->daysTo($today, $race->getDate());
            $result = trim((string) ($race->getResult() ?? ''));

            $statusClass = 'badge-future';
            $statusLabel = $days < 0 ? 'Passée' : sprintf('S-%d', (int) round($days / 7));

            if ($result !== '') {
                $statusClass = 'badge-done';
                $statusLabel = '✓ Terminée';
            } elseif ($days <= 7) {
                $statusClass = 'badge-next';
                $statusLabel = sprintf('J-%d', $days);
            }

            $rows[] = [
                'statusClass' => $statusClass,
                'statusLabel' => $statusLabel,
                'name' => $race->getName(),
                'date' => $race->getDate(),
                'dist' => (string) ($race->getDistance() ?? ''),
                'obj' => (string) ($race->getObjective() ?? ''),
                'real' => $result,
            ];
        }

        return $rows;
    }

    private function daysTo(\DateTimeImmutable $today, string $raceDate): int
    {
        try {
            $target = new \DateTimeImmutable($raceDate);
        } catch (\Throwable) {
            return 0;
        }

        $diffSeconds = $target->getTimestamp() - $today->getTimestamp();
        return (int) round($diffSeconds / 86400);
    }

    /**
     * @param array<int, RunLog> $logs
     * @return array<int, array{label:string,km:float,height:int}>
     */
    private function buildMonthlyBars(array $logs): array
    {
        $labels = ['Jan', 'Fev', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aou', 'Sep', 'Oct', 'Nov', 'Dec'];
        $monthly = array_fill_keys($labels, 0.0);

        foreach ($logs as $log) {
            $date = $log->getDate();
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }

            $month = (int) substr($date, 5, 2);
            $km = (float) ($log->getKm() ?? 0.0);

            if ($month >= 1 && $month <= 12) {
                $label = $labels[$month - 1];
                $monthly[$label] += $km;
            }
        }

        $maxKm = max(1.0, ...array_values($monthly));
        $bars = [];
        foreach ($labels as $label) {
            $km = round($monthly[$label], 1);
            $bars[] = [
                'label' => $label,
                'km' => $km,
                'height' => (int) round(($km / $maxKm) * 100),
            ];
        }

        return $bars;
    }

    /** @param array<int, RunLog> $logs */
    private function computeAverageAllure(array $logs): string
    {
        $all = [];
        foreach ($logs as $log) {
            $sec = $this->paceToSeconds($log->getAllure());
            if ($sec !== null) {
                $all[] = $sec;
            }
        }

        if ($all === []) {
            return '—';
        }

        $avg = (int) round(array_sum($all) / count($all));
        return $this->secondsToMmSs($avg);
    }

    /** @param array<int, RunLog> $logs */
    private function computeLongestDistance(array $logs): float
    {
        $max = 0.0;
        foreach ($logs as $log) {
            $km = (float) ($log->getKm() ?? 0.0);
            if ($km > $max) {
                $max = $km;
            }
        }

        return round($max, 1);
    }

    /** @param array<int, RunLog> $logs */
    private function computeLongestDuration(array $logs): string
    {
        $max = 0;
        foreach ($logs as $log) {
            $sec = $this->durationToSeconds($log->getDuration());
            if ($sec !== null && $sec > $max) {
                $max = $sec;
            }
        }

        return $max > 0 ? $this->secondsToDuration($max) : '—';
    }

    /** @param array<int, RunLog> $logs */
    private function computeAverageBpm(array $logs): int|string
    {
        $vals = [];
        foreach ($logs as $log) {
            $bpm = $log->getBpm();
            if ($bpm !== null) {
                $vals[] = $bpm;
            }
        }

        if ($vals === []) {
            return '—';
        }

        return (int) round(array_sum($vals) / count($vals));
    }

    /**
     * @param array<int, RunLog> $logs
     * @return array{0: array<int, array{label:string,time:string,pace:string,color:string}>, 1: string}
     */
    private function buildProjections(array $logs): array
    {
        $valid = array_values(array_filter($logs, function (RunLog $log): bool {
            return $log->getDate() !== '' && $this->paceToSeconds($log->getAllure()) !== null && strtoupper((string) ($log->getRunType() ?? '')) !== 'RACE';
        }));

        usort($valid, static fn (RunLog $a, RunLog $b) => strcmp($b->getDate(), $a->getDate()));
        $recent = array_slice($valid, 0, 5);

        if ($recent === []) {
            return [[], 'Pas encore assez de donnees.'];
        }

        $paceSecList = [];
        $runsWithGap = 0;
        foreach ($recent as $log) {
            $gapSec = $this->paceToSeconds($log->getGap());
            $allureSec = $this->paceToSeconds($log->getAllure());
            $hasDplus = ($log->getDplus() ?? 0) > 0;

            if ($gapSec !== null && $hasDplus) {
                $paceSecList[] = $gapSec;
                $runsWithGap++;
                continue;
            }
            if ($allureSec !== null) {
                $paceSecList[] = $allureSec;
            }
        }

        if ($paceSecList === []) {
            return [[], 'Pas encore assez de donnees.'];
        }

        $avgSecPerKm = array_sum($paceSecList) / count($paceSecList);
        $distances = [
            ['label' => '5 km', 'dist' => 5.0],
            ['label' => '10 km', 'dist' => 10.0],
            ['label' => '21 km', 'dist' => 21.1],
            ['label' => '42 km', 'dist' => 42.2],
        ];
        $colors = ['#e8c678', '#8b9cf4', '#e05580', '#4ade80'];

        $projections = [];
        foreach ($distances as $idx => $d) {
            $timeSec = (int) round($avgSecPerKm * $d['dist'] * 1.22);
            $paceSec = (int) round($timeSec / $d['dist']);
            $projections[] = [
                'label' => $d['label'],
                'time' => $this->trimDuration($this->secondsToDuration($timeSec)),
                'pace' => $this->secondsToMmSs($paceSec),
                'color' => $colors[$idx % count($colors)],
            ];
        }

        $paceLabel = $runsWithGap > 0 ? 'GAP moy.' : 'Allure moy.';
        $avgAllureStr = $this->secondsToMmSs((int) round($avgSecPerKm));
        $gapNote = $runsWithGap > 0
            ? sprintf(' · %d/%d sorties avec D+ corrige (GAP)', $runsWithGap, count($recent))
            : ' · Aucun D+ renseigne - allure brute utilisee';

        $meta = sprintf('%s des %d dernieres sorties: %s/km · Projection: allure moyenne × 1.22%s', $paceLabel, count($recent), $avgAllureStr, $gapNote);

        return [$projections, $meta];
    }

    private function paceToSeconds(?string $pace): ?int
    {
        $seconds = null;
        if ($pace && str_contains($pace, ':')) {
            $parts = explode(':', $pace);
            if (count($parts) === 2) {
                $m = (int) $parts[0];
                $s = (int) $parts[1];
                if ($m >= 0 && $s >= 0 && $s < 60) {
                    $seconds = $m * 60 + $s;
                }
            }
        }
        return $seconds;
    }

    private function durationToSeconds(?string $duration): ?int
    {
        $seconds = null;
        if ($duration) {
            $parts = explode(':', $duration);
            if (count($parts) === 3) {
                $seconds = ((int) $parts[0] * 3600) + ((int) $parts[1] * 60) + (int) $parts[2];
            } elseif (count($parts) === 2) {
                $seconds = ((int) $parts[0] * 60) + (int) $parts[1];
            }
        }
        return $seconds;
    }

    private function secondsToDuration(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }

    private function secondsToMmSs(int $seconds): string
    {
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;
        return sprintf('%02d:%02d', $m, $s);
    }

    private function trimDuration(string $duration): string
    {
        return str_starts_with($duration, '00:') ? substr($duration, 3) : $duration;
    }
}
