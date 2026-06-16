<?php

namespace App\Service;

use App\Entity\Race;
use App\Entity\RunLog;
use App\Entity\User;
use App\Repository\RaceRepository;
use App\Repository\RunLogRepository;
use App\Service\HealthPauseService;

/**
 * Builds dashboard metric aggregates from training logs and races.
 */
final class DashboardMetricsFacadeService
{
    // Riegel fatigue exponent: T2 = T1 * (D2/D1)^EXPONENT.
    private const RIEGEL_EXPONENT = 1.06;
    // Single tuning point: chart window long enough for trend, short enough to stay readable.
    private const PROJECTION_HISTORY_MONTHS = 8;
    private const PROJECTION_RULES = "Modèle de projection: formule de Riegel T2 = T1 × (D2/D1)^1.06 (T1 = temps sur la distance de référence).";

    public function __construct(
        private RunLogRepository $runLogs,
        private RaceRepository $races,
        private DashboardAdvancedMetricsService $advancedMetrics,
        private DashboardEfMetricsService $efMetrics,
        private HealthPauseService $healthPauseService,
    ) {}

    /**
     * Builds the full dashboard payload used by frontend widgets.
     *
     * @return array<string,mixed>
     */
    public function build(User $user): array
    {
        $logs  = $this->runLogs->findBy(['user' => $user], ['date' => 'DESC'], 500);
        $races = $this->races->findBy(['user' => $user], ['date' => 'ASC']);

        $pauseStatus = $this->healthPauseService->getStatus($user);
        $pauseActive = $user->isOnHealthPause();
        $recoveryWindowActive = $user->isInRecoveryWindow();

        [$projections, $projectionsMeta, $projectionsHistory, $projectionBase] = $this->buildProjections($logs);
        $planWidgets = $this->advancedMetrics->buildPlanWidgets($user, $pauseActive);

        $raceProjection = $this->buildRaceProjectionCard($projections, $races, $projectionBase);
        if ($pauseActive) {
            $raceProjection = null;
        } elseif ($raceProjection !== null && $recoveryWindowActive) {
            $raceProjection['statusText'] = 'Tu as une course prévue — adapte selon comment tu te sens.';
            $raceProjection['status'] = 'paused';
        }

        $trainingLoadArg = $pauseActive || $recoveryWindowActive;
        $trainingLoad = $this->advancedMetrics->buildTrainingLoad($logs, $trainingLoadArg);
        if ($recoveryWindowActive && !$pauseActive) {
            $trainingLoad['statusKey'] = 'recovery';
            $trainingLoad['statusLabel'] = 'Reprise en cours';
            $trainingLoad['recommendation'] = 'Commence doucement — la charge de référence se recalibrera dans quelques semaines.';
        }

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
            'projectionsNarrative' => $pauseActive ? null : $this->buildProjectionNarrative($projectionsHistory),
            'raceProjection' => $raceProjection,
            'trainingLoad' => $trainingLoad,
            'efKpis' => $this->efMetrics->buildEfKpis($logs),
            'ef' => $this->efMetrics->buildEfSection($logs),
            'coherenceAlerts' => $this->advancedMetrics->buildCoherenceAlerts($logs, $pauseActive),
            'racesTable' => $this->buildDashboardRacesTable($user),
            'planProgress' => $planWidgets['progress'],
            'planCalendar' => $planWidgets['calendar'],
            'pauseStatus' => $pauseStatus,
        ];
    }

    /**
     * Builds a human-readable narrative from the projection history.
     * Picks the most meaningful improving distance and summarises the gain over the visible period.
     *
     * Returns null when history has fewer than 2 data points or changes are below 30 seconds.
     *
     * @param array{hasData:bool,labels:array<int,string>,series:array<int,array{label:string,color:string,values:array<int,int|null>}>} $history
     * @return array{text:string,improving:bool,distance:string}|null
     */
    private function buildProjectionNarrative(array $history): ?array
    {
        if (!($history['hasData'] ?? false)) {
            return null;
        }

        $labels     = $history['labels'] ?? [];
        $narratives = [];

        foreach ($history['series'] as $serie) {
            $label  = (string) ($serie['label'] ?? '');
            $values = $serie['values'] ?? [];

            $firstIdx = null;
            $firstVal = null;
            $lastIdx  = null;
            $lastVal  = null;

            foreach ($values as $idx => $v) {
                if ($v === null) {
                    continue;
                }
                if ($firstIdx === null) {
                    $firstIdx = $idx;
                    $firstVal = $v;
                }
                $lastIdx = $idx;
                $lastVal = $v;
            }

            if ($firstIdx === null || $lastIdx === null || $firstIdx === $lastIdx) {
                continue;
            }

            // Negative diff = got faster = improvement.
            $diff    = (int) $lastVal - (int) $firstVal;
            $absDiff = abs($diff);
            // 30-second threshold: smaller changes are noise.
            if ($absDiff < 30) {
                continue;
            }

            $minDiff   = intdiv($absDiff, 60);
            $secDiff   = $absDiff % 60;
            $timeStr   = $minDiff > 0
                ? sprintf('%d min %02d sec', $minDiff, $secDiff)
                : sprintf('%d sec', $secDiff);
            $direction = $diff < 0 ? 'gagné' : 'perdu';

            $narratives[] = [
                'distance'   => $label,
                'direction'  => $direction,
                'timeStr'    => $timeStr,
                'span'       => $lastIdx - $firstIdx,
                'improving'  => $diff < 0,
                'firstLabel' => $labels[$firstIdx] ?? '',
                'lastLabel'  => $labels[$lastIdx] ?? '',
            ];
        }

        if ($narratives === []) {
            return null;
        }

        // Prefer improving distances, then longest span.
        usort($narratives, static function (array $a, array $b): int {
            if ($a['improving'] !== $b['improving']) {
                return ($b['improving'] ? 1 : 0) - ($a['improving'] ? 1 : 0);
            }
            return $b['span'] - $a['span'];
        });

        $best = $narratives[0];
        return [
            'text'      => sprintf(
                '%s : tu as %s %s (%s → %s).',
                $best['distance'],
                $best['direction'],
                $best['timeStr'],
                $best['firstLabel'],
                $best['lastLabel']
            ),
            'improving' => $best['improving'],
            'distance'  => $best['distance'],
        ];
    }

    /**
     * Matches the next upcoming race against current projected times.
     * Returns a structured projection card or null if no match is possible.
     *
     * @param array<int,array{label:string,time:string,pace:string,color:string}> $projections
     * @param array<int,Race> $races
     * @return array{raceName:string,raceDate:string,raceDist:string,daysTo:int,objective:string,projected:string,status:string,statusText:string}|null
     */
    private function buildRaceProjectionCard(array $projections, array $races, ?array $base = null): ?array
    {
        $today = (new \DateTimeImmutable('today'))->setTime(0, 0, 0);

        $nextRace = null;
        foreach ($races as $race) {
            if ($this->raceIsClosed($race)) {
                continue; // already finished, DNS or DNF
            }
            $raceDate = \DateTimeImmutable::createFromFormat('Y-m-d', $race->getDate());
            if (!$raceDate instanceof \DateTimeImmutable) {
                continue;
            }
            if ((int) $today->diff($raceDate)->format('%r%a') < 0) {
                continue; // past
            }
            $nextRace = $race;
            break;
        }

        if ($nextRace === null || $projections === []) {
            return null;
        }

        $raceDistRaw = trim((string) ($nextRace->getDistance() ?? ''));
        $raceDistKm  = $this->parseDistanceKm($raceDistRaw);
        if ($raceDistKm === null) {
            return null;
        }

        // Project the time for the ACTUAL race distance (not snapped to a fixed bucket).
        if ($base !== null && isset($base['avgSecPerKm'], $base['sourceDistanceKm'])) {
            $projSec  = $this->projectFromSamples(
                $base['samples'] ?? [],
                (float) $base['avgSecPerKm'],
                (float) $base['sourceDistanceKm'],
                $raceDistKm
            );
            $projTime = $this->trimDuration($this->secondsToDuration($projSec));
        } else {
            // Fallback: closest fixed projection bucket (used only when no base pace is available).
            $bestProj = null;
            $bestDiff = PHP_FLOAT_MAX;
            foreach ($projections as $proj) {
                $projKm = $this->parseDistanceKm((string) ($proj['label'] ?? ''));
                if ($projKm === null) {
                    continue;
                }
                $diff = abs($projKm - $raceDistKm);
                if ($diff < $bestDiff) {
                    $bestDiff = $diff;
                    $bestProj = $proj;
                }
            }

            if ($bestProj === null || $bestDiff > 5.0) {
                return null;
            }

            $projTime = (string) ($bestProj['time'] ?? '');
            $projSec  = $this->durationToSeconds($projTime);
        }

        $objective = trim((string) ($nextRace->getObjective() ?? ''));
        $objSec    = $objective !== '' ? $this->durationToSeconds($objective) : null;

        if ($projSec === null) {
            return null;
        }

        // No objective set => no objective-based status message at all.
        $status     = 'on_track';
        $statusText = '';

        if ($objSec !== null) {
            $diff    = $projSec - $objSec;
            $absDiff = abs($diff);
            $minD    = intdiv($absDiff, 60);
            $secD    = $absDiff % 60;
            $diffStr = $minD > 0
                ? sprintf('%d min %02d sec', $minD, $secD)
                : sprintf('%d sec', $secD);

            if ($diff < -30) {
                $status     = 'ahead';
                $statusText = sprintf('Tu es %s en avance sur ton objectif ✅', $diffStr);
            } elseif ($diff > 60) {
                $status     = 'behind';
                $statusText = sprintf('Tu es %s derrière ton objectif → augmente progressivement le rythme', $diffStr);
            } else {
                $statusText = 'Dans les clous par rapport à ton objectif ✅';
            }
        }

        $raceDateDt = \DateTimeImmutable::createFromFormat('Y-m-d', $nextRace->getDate());
        $daysTo     = $raceDateDt instanceof \DateTimeImmutable
            ? (int) $today->diff($raceDateDt)->format('%r%a')
            : 0;

        return [
            'raceName'   => (string) ($nextRace->getName() ?? ''),
            'raceDate'   => (string) $nextRace->getDate(),
            'raceDist'   => $raceDistRaw,
            'daysTo'     => $daysTo,
            'daysLabel'  => $this->humanizeDaysTo($daysTo),
            'objective'  => $objective,
            'projected'  => $projTime,
            'status'     => $status,
            'statusText' => $statusText,
        ];
    }

    /**
     * Returns true when a race should no longer be treated as upcoming
     * (it has a recorded result, or was marked DNS / DNF).
     */
    private function raceIsClosed(Race $race): bool
    {
        if (trim((string) ($race->getResult() ?? '')) !== '') {
            return true;
        }

        return in_array($race->getDnfStatus(), ['dns', 'dnf'], true);
    }

    /**
     * Converts a signed day delta into a human-readable French label.
     * Examples: -1 => "Passé", 0 => "Aujourd'hui", 1 => "Demain",
     * 3 => "Dans 3 jours", 14 => "Dans 2 semaines", 30 => "Dans 1 mois".
     */
    private function humanizeDaysTo(int $days): string
    {
        if ($days < 0) {
            return 'Passé';
        }
        if ($days === 0) {
            return "Aujourd'hui";
        }
        if ($days === 1) {
            return 'Demain';
        }
        if ($days <= 6) {
            return sprintf('Dans %d jours', $days);
        }
        if ($days <= 27) {
            $weeks = (int) round($days / 7);
            return sprintf('Dans %d semaine%s', $weeks, $weeks > 1 ? 's' : '');
        }
        $months = max(1, (int) round($days / 30));
        return sprintf('Dans %d mois', $months);
    }

    /**
     * Parses a distance label like "10km", "21 km", "Semi", "42km" into kilometers.
     */
    private function parseDistanceKm(string $raw): ?float
    {
        $lower = strtolower(trim($raw));
        if (str_contains($lower, 'semi') || (str_contains($lower, '21') && !str_contains($lower, '421'))) {
            return 21.1;
        }
        if (str_contains($lower, 'marathon') || str_contains($lower, '42')) {
            return 42.2;
        }
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*km?/i', $raw, $matches)) {
            return (float) str_replace(',', '.', $matches[1]);
        }
        return null;
    }

    /**
     * @return array<int, array{statusClass:string,statusLabel:string,upcoming:bool,name:string,date:string,dist:string,obj:string,real:string}>
     */
    private function buildDashboardRacesTable(User $user): array
    {
        $races = $this->races->findBy(['user' => $user], ['date' => 'ASC']);
        $today = (new \DateTimeImmutable('now'))->setTime(0, 0, 0);

        $rows = [];
        foreach ($races as $race) {
            $days = $this->daysTo($today, $race->getDate());
            $result = trim((string) ($race->getResult() ?? ''));
            $dnfStatus = $race->getDnfStatus();

            // Default: an upcoming race, labelled with a human relative date.
            $statusClass = $days <= 14 ? 'badge-next' : 'badge-future';
            $statusLabel = $this->humanizeDaysTo($days);
            $upcoming = true;

            if ($dnfStatus === 'dns') {
                $statusClass = 'badge-dns';
                $statusLabel = 'DNS';
                $upcoming = false;
            } elseif ($dnfStatus === 'dnf') {
                $statusClass = 'badge-dnf';
                $statusLabel = 'DNF';
                $upcoming = false;
            } elseif ($result !== '') {
                $statusClass = 'badge-done';
                $statusLabel = '✓ Terminée';
                $upcoming = false;
            } elseif ($days < 0) {
                // Past race without a recorded result: keep it out of "à venir".
                $statusClass = 'badge-future';
                $statusLabel = 'Passé';
                $upcoming = false;
            }

            $rows[] = [
                'statusClass' => $statusClass,
                'statusLabel' => $statusLabel,
                'upcoming' => $upcoming,
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
                $monthly[$labels[$month - 1]] += $km;
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
        return $this->secondsToMmSs((int) round(array_sum($all) / count($all)));
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
        // Filter to EF only — BPM label on dashboard is "BPM MOY. EF"
        $vals = [];
        foreach ($logs as $log) {
            if (strtoupper((string) ($log->getRunType() ?? '')) !== 'EF') {
                continue;
            }
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
            return [[], 'Pas encore assez de donnees.', $history, null];
        }

        $projectionBase = $this->computeProjectionBasePace($recent);
        $paceSecList = $projectionBase['paceSecList'];
        $runsWithGap = $projectionBase['runsWithGap'];
        $sourceDistanceKm = $projectionBase['sourceDistanceKm'];

        if ($paceSecList === []) {
            return [[], 'Pas encore assez de donnees.', $history, null];
        }

        $avgSecPerKm = array_sum($paceSecList) / count($paceSecList);
        $samples = $projectionBase['samples'];

        $projections = [];
        foreach ($distances as $idx => $d) {
            $timeSec = $this->projectFromSamples($samples, $avgSecPerKm, $sourceDistanceKm, (float) $d['dist']);
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

        return [$projections, $meta, $history, ['avgSecPerKm' => $avgSecPerKm, 'sourceDistanceKm' => $sourceDistanceKm, 'samples' => $samples]];
    }

    /**
     * @param array<int,RunLog> $runs
     * @return array{paceSecList:array<int,int>,runsWithGap:int,sourceDistanceKm:float,samples:array<int,array{paceSec:int,km:float|null}>}
     */
    private function computeProjectionBasePace(array $runs): array
    {
        $paceSecList = [];
        $runsWithGap = 0;
        $distanceKmList = [];
        $samples = [];

        foreach ($runs as $log) {
            $gapSec = $this->paceToSeconds($log->getGap());
            $allureSec = $this->paceToSeconds($log->getAllure());
            $hasDplus = ($log->getDplus() ?? 0) > 0;
            $km = (float) ($log->getKm() ?? 0.0);

            if ($gapSec !== null && $hasDplus) {
                $paceSecList[] = $gapSec;
                $runsWithGap++;
                if ($km > 0.0) {
                    $distanceKmList[] = $km;
                }
                $samples[] = ['paceSec' => $gapSec, 'km' => $km > 0.0 ? $km : null];
                continue;
            }
            if ($allureSec !== null) {
                $paceSecList[] = $allureSec;
                if ($km > 0.0) {
                    $distanceKmList[] = $km;
                }
                $samples[] = ['paceSec' => $allureSec, 'km' => $km > 0.0 ? $km : null];
            }
        }

        $sourceDistanceKm = $this->computeMedianDistance($distanceKmList);

        return ['paceSecList' => $paceSecList, 'runsWithGap' => $runsWithGap, 'sourceDistanceKm' => $sourceDistanceKm, 'samples' => $samples];
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
                'Historique sur %d mois · Chaque barre = projection basée sur les 5 dernières sorties du mois concerné (⚠ début de mois = peu de données, valeur instable) ; ' . self::PROJECTION_RULES,
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
                $values[] = $this->projectFromSamples($base['samples'] ?? [], $avgSecPerKm, $sourceDistanceKm, (float) $distance['dist']);
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

        // Riegel: T2 = T1 * (D2/D1)^1.06, with T1 = reference time over the source distance.
        $sourceSeconds = $avgSecPerKm * $safeSourceDistance;
        $projected = $sourceSeconds * ($safeTargetDistance / $safeSourceDistance) ** self::RIEGEL_EXPONENT;

        return (int) round($projected);
    }

    /**
     * Distance-consistent Riegel projection.
     * Projects each sample from its OWN run distance, then averages, so a short
     * fast run is not re-labelled as the median distance and sped up twice.
     * Falls back to the aggregate pace/median-distance model when no per-run
     * distances are available.
     *
     * @param array<int,array{paceSec:int,km:float|null}> $samples
     */
    private function projectFromSamples(array $samples, float $avgSecPerKm, float $sourceDistanceKm, float $targetDistanceKm): int
    {
        $projected = [];
        foreach ($samples as $sample) {
            $km      = $sample['km'] ?? null;
            $paceSec = $sample['paceSec'] ?? null;
            if ($km === null || $km <= 0.0 || $paceSec === null) {
                continue;
            }
            $projected[] = $this->projectedTimeSeconds((float) $paceSec, (float) $km, $targetDistanceKm);
        }

        if ($projected !== []) {
            return (int) round(array_sum($projected) / count($projected));
        }

        // No per-run distances => fall back to aggregate model.
        return $this->projectedTimeSeconds($avgSecPerKm, $sourceDistanceKm, $targetDistanceKm);
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
