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
    private const PROJECTION_FACTOR_LONGER_DISTANCE = 1.06;
    private const PROJECTION_FACTOR_MARATHON = 1.12;
    private const PROJECTION_FACTOR_SHORTER_DISTANCE = 0.85;
    // Single tuning point: chart window long enough for trend, short enough to stay readable.
    private const PROJECTION_HISTORY_MONTHS = 8;
    private const PROJECTION_RULES = "Règles de projection: \r\n- de plus courte à inférieure ou égale à 5 km x0.85 ; \r\n- jusqu'à 10kmde plus x1.0; \r\n- entre +10km et -40km x1.06 ; \r\n- marathon x1.12";


    public function __construct(
        private RunLogRepository $runLogs,
        private RaceRepository $races,
        private DashboardAdvancedMetricsService $advancedMetrics,
        private DashboardEfMetricsService $efMetrics,
    ) {}

    /**
     * Builds the full dashboard payload used by frontend widgets.
     *
     * @return array<string,mixed>
     */
    public function build(User $user): array
    {
        $logs = $this->runLogs->findBy(['user' => $user], ['date' => 'DESC'], 500);

        [$projections, $projectionsMeta, $projectionsHistory] = $this->buildProjections($logs);
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
            'projectionsHistory' => $projectionsHistory,
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
            // Use week-based countdown for badges beyond D-7.
            $weeksToRace = (int) ceil(max(0, $days) / 7);
            $result = trim((string) ($race->getResult() ?? ''));

            $statusClass = 'badge-future';
            $statusLabel = $days < 0 ? 'Passée' : sprintf('S-%d', $weeksToRace);

            if ($result !== '') {
                $statusClass = 'badge-done';
                $statusLabel = '✓ Terminée';
            } elseif ($days <= 14) {
                $statusClass = 'badge-next';
                $statusLabel = $days <= 7
                    ? sprintf('J-%d', $days)
                    : sprintf('S-%d', $weeksToRace);
            } elseif ($weeksToRace <= 2) {
                // Keep visual status coherent: S-1/S-2 must use the "next" badge color.
                $statusClass = 'badge-next';
                $statusLabel = sprintf('S-%d', $weeksToRace);
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
        // Convert signed seconds gap to rounded calendar days.
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

        // Prevent division by zero and scale all bars against the top month.
        $maxKm = max(1.0, ...array_values($monthly));
        $bars = [];
        foreach ($labels as $label) {
            $km = round($monthly[$label], 1);
            $bars[] = [
                'label' => $label,
                'km' => $km,
                // Height is a normalized percentage [0..100] for chart rendering.
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

        // Mean pace over all valid runs.
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

        // Mean BPM over runs where BPM is provided.
        return (int) round(array_sum($vals) / count($vals));
    }

    /**
     * @param array<int, RunLog> $logs
     * @return array{0: array<int, array{label:string,time:string,pace:string,color:string}>, 1: string, 2: array<string,mixed>}
     */
    private function buildProjections(array $logs): array
    {
        $valid = array_values(array_filter($logs, function (RunLog $log): bool {
            return $log->getDate() !== '' && $this->paceToSeconds($log->getAllure()) !== null && strtoupper((string) ($log->getRunType() ?? '')) !== 'RACE';
        }));

        usort($valid, static fn(RunLog $a, RunLog $b) => strcmp($b->getDate(), $a->getDate()));
        $recent = array_slice($valid, 0, 5);
        $distances = [
            ['label' => '5 km', 'dist' => 5.0],
            ['label' => '10 km', 'dist' => 10.0],
            ['label' => '21 km', 'dist' => 21.1],
            ['label' => '42 km', 'dist' => 42.2],
        ];
        $colors = ['var(--z1)', 'var(--z2)', 'var(--z3)', 'var(--z5)'];
        $history = $this->buildProjectionHistory($valid, $distances, $colors);

        if ($recent === []) {
            return [[], 'Pas encore assez de donnees.', $history];
        }

        $projectionBase = $this->computeProjectionBasePace($recent);
        $paceSecList = $projectionBase['paceSecList'];
        $runsWithGap = $projectionBase['runsWithGap'];
        $sourceDistanceKm = $projectionBase['sourceDistanceKm'];

        if ($paceSecList === []) {
            return [[], 'Pas encore assez de donnees.', $history];
        }

        // Projection base pace = average sec/km of latest representative runs.
        $avgSecPerKm = array_sum($paceSecList) / count($paceSecList);

        $projections = [];
        foreach ($distances as $idx => $d) {
            // Distance-aware projection from reference distance with fixed coefficients.
            $timeSec = $this->projectedTimeSeconds($avgSecPerKm, $sourceDistanceKm, (float) $d['dist']);
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
            ? sprintf(' · D+ present sur %d/%d sorties: GAP utilisee quand disponible', $runsWithGap, count($recent))
            : ' · Aucun D+ renseigne - allure brute utilisee';

        $meta = sprintf(
            '%s (5 dernieres sorties): %s/km · Distance de reference: %.1f km · ' . self::PROJECTION_RULES,
            $paceLabel,
            $avgAllureStr,
            $sourceDistanceKm,
            $gapNote
        );

        return [$projections, $meta, $history];
    }

    /**
     * @param array<int,RunLog> $runs
     * @return array{paceSecList:array<int,int>,runsWithGap:int,sourceDistanceKm:float}
     */
    private function computeProjectionBasePace(array $runs): array
    {
        $paceSecList = [];
        $runsWithGap = 0;
        $distanceKmList = [];

        foreach ($runs as $log) {
            $gapSec = $this->paceToSeconds($log->getGap());
            $allureSec = $this->paceToSeconds($log->getAllure());
            $hasDplus = ($log->getDplus() ?? 0) > 0;
            $km = (float) ($log->getKm() ?? 0.0);

            // Prefer GAP pace when climb data exists, otherwise keep raw pace.
            if ($gapSec !== null && $hasDplus) {
                $paceSecList[] = $gapSec;
                $runsWithGap++;
                if ($km > 0.0) {
                    $distanceKmList[] = $km;
                }
                continue;
            }
            if ($allureSec !== null) {
                $paceSecList[] = $allureSec;
                if ($km > 0.0) {
                    $distanceKmList[] = $km;
                }
            }
        }

        // Median distance is more robust than mean when one run is unusually short/long.
        $sourceDistanceKm = $this->computeMedianDistance($distanceKmList);

        return ['paceSecList' => $paceSecList, 'runsWithGap' => $runsWithGap, 'sourceDistanceKm' => $sourceDistanceKm];
    }

    /**
     * @param array<int,RunLog> $validRuns
     * @param array<int,array{label:string,dist:float}> $distances
     * @param array<int,string> $colors
     * @return array{hasData:bool,labels:array<int,string>,series:array<int,array{label:string,color:string,values:array<int,int|null>}>,meta:string,emptyMessage:string}
     */
    private function buildProjectionHistory(array $validRuns, array $distances, array $colors): array
    {
        $monthKeys = [];
        $todayMonth = (new \DateTimeImmutable('first day of this month'))->setTime(0, 0, 0);
        for ($i = self::PROJECTION_HISTORY_MONTHS - 1; $i >= 0; $i--) {
            $monthKeys[] = $todayMonth->modify(sprintf('-%d month', $i))->format('Y-m');
        }

        $runsByMonth = [];
        foreach ($validRuns as $run) {
            $date = $run->getDate();
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }
            $monthKey = substr($date, 0, 7);
            if (!in_array($monthKey, $monthKeys, true)) {
                continue;
            }
            $runsByMonth[$monthKey] ??= [];
            $runsByMonth[$monthKey][] = $run;
        }
        $monthLabelFormatter = static function (string $monthKey): string {
            $dt = \DateTimeImmutable::createFromFormat('Y-m', $monthKey);
            if (!$dt instanceof \DateTimeImmutable) {
                return $monthKey;
            }

            $labels = ['Jan', 'Fev', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aou', 'Sep', 'Oct', 'Nov', 'Dec'];
            $month = (int) $dt->format('n');

            return sprintf('%s %s', $labels[$month - 1] ?? $dt->format('m'), $dt->format('y'));
        };
        $labels = array_map($monthLabelFormatter, $monthKeys);
        $series = $this->buildProjectionHistorySeries($monthKeys, $runsByMonth, $distances, $colors);
        $hasData = false;
        foreach ($series as $line) {
            foreach ($line['values'] as $value) {
                if ($value !== null) {
                    $hasData = true;
                    break 2;
                }
            }
        }

        return [
            'hasData' => $hasData,
            'labels' => $labels,
            'series' => $series,
            'meta' => sprintf(
                'Historique sur %d mois: chaque mois utilise 5 dernieres sorties comme base ; ' . self::PROJECTION_RULES,
                self::PROJECTION_HISTORY_MONTHS
            ),
            'emptyMessage' => 'Pas assez de donnees mensuelles pour tracer l historique des projections.',
        ];
    }

    /**
     * @param array<int,string> $monthKeys
     * @param array<string,array<int,RunLog>> $runsByMonth
     * @param array<int,array{label:string,dist:float}> $distances
     * @param array<int,string> $colors
     * @return array<int,array{label:string,color:string,values:array<int,int|null>}>
     */
    private function buildProjectionHistorySeries(array $monthKeys, array $runsByMonth, array $distances, array $colors): array
    {
        $series = [];
        foreach ($distances as $idx => $distance) {
            $values = [];
            foreach ($monthKeys as $monthKey) {
                $runs = $runsByMonth[$monthKey] ?? [];
                usort($runs, static fn(RunLog $a, RunLog $b): int => strcmp($b->getDate(), $a->getDate()));
                $sample = array_slice($runs, 0, 5);
                $base = $this->computeProjectionBasePace($sample);
                $paceSecList = $base['paceSecList'];
                if ($paceSecList === []) {
                    $values[] = null;
                    continue;
                }
                $avgSecPerKm = array_sum($paceSecList) / count($paceSecList);
                $sourceDistanceKm = (float) ($base['sourceDistanceKm'] ?? 10.0);
                $values[] = $this->projectedTimeSeconds($avgSecPerKm, $sourceDistanceKm, (float) $distance['dist']);
            }

            $series[] = [
                'label' => $distance['label'],
                'color' => $colors[$idx % count($colors)],
                'values' => $values,
            ];
        }

        return $series;
    }

    private function projectedTimeSeconds(float $avgSecPerKm, float $sourceDistanceKm, float $targetDistanceKm): int
    {
        $safeSourceDistance = max(1.0, $sourceDistanceKm);
        $safeTargetDistance = max(1.0, $targetDistanceKm);
        $linearSeconds = $avgSecPerKm * $safeTargetDistance;

        // Fixed projection rules requested by product:
        // - shorter target distance: x0.85 (up to 5 km gap)
        // - similar distance (up to 10 km gap): x1.0
        // - longer target distance: x1.06
        // - marathon target (42 km): x1.12
        if ($safeTargetDistance < $safeSourceDistance || $safeTargetDistance === $safeSourceDistance || ($safeTargetDistance > $safeSourceDistance && $safeTargetDistance - $safeSourceDistance <= 5)) {
            return (int) round($linearSeconds * self::PROJECTION_FACTOR_SHORTER_DISTANCE);
        }

        if ($safeTargetDistance > $safeSourceDistance && $safeTargetDistance - $safeSourceDistance > 5 && $safeTargetDistance - $safeSourceDistance <= 10) {
            return (int) round($linearSeconds);
        }
        $factor = self::PROJECTION_FACTOR_LONGER_DISTANCE;
        if ($safeTargetDistance >= 40.0) {
            $factor = self::PROJECTION_FACTOR_MARATHON;
        }

        return (int) round($linearSeconds * $factor);
    }

    /** @param array<int,float> $distancesKm */
    private function computeMedianDistance(array $distancesKm): float
    {
        if ($distancesKm === []) {
            return 10.0;
        }

        sort($distancesKm);
        $count = count($distancesKm);
        $middle = intdiv($count, 2);

        if ($count % 2 === 0) {
            return ($distancesKm[$middle - 1] + $distancesKm[$middle]) / 2.0;
        }

        return $distancesKm[$middle];
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
                    // mm:ss per km -> total seconds per km.
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
                // hh:mm:ss
                $seconds = ((int) $parts[0] * 3600) + ((int) $parts[1] * 60) + (int) $parts[2];
            } elseif (count($parts) === 2) {
                // mm:ss
                $seconds = ((int) $parts[0] * 60) + (int) $parts[1];
            }
        }
        return $seconds;
    }

    private function secondsToDuration(int $seconds): string
    {
        // Normalize absolute duration into hh:mm:ss.
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }

    private function secondsToMmSs(int $seconds): string
    {
        // Pace display format (minutes per kilometer).
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;
        return sprintf('%02d:%02d', $m, $s);
    }

    private function trimDuration(string $duration): string
    {
        return str_starts_with($duration, '00:') ? substr($duration, 3) : $duration;
    }
}
