<?php

namespace App\Service;

use App\Entity\Plan;
use App\Entity\RunLog;
use App\Repository\RunLogRepository;

/**
 * Builds per-plan evolution recaps: quarter-by-quarter progression and a global
 * first->last delta. Source: run logs linked to the plan (via plannedSession).
 */
final class PlanEvolutionService
{
    private const UP = '↗';
    private const DOWN = '↘';
    private const FLAT = '→';

    public function __construct(private RunLogRepository $runLogRepository)
    {
    }

    /**
     * @return array{
     *   hasData: bool,
     *   quarters: array<int, array{
     *     key:string, label:string, runs:int, km:float,
     *     avgBpmEf:?int, avgPace:?string, avgPaceEf:?string, avgPaceTempo:?string,
     *     dplus:int, totalTime:?string
     *   }>,
     *   delta: array<string, array{from:string, to:string, gap:string, trend:string}>
     * }
     */
    public function buildQuarterlyRecap(Plan $plan): array
    {
        $logs = $this->runLogRepository->findByPlan($plan);
        if ($logs === []) {
            return ['hasData' => false, 'quarters' => [], 'delta' => []];
        }

        $groups = [];
        foreach ($logs as $log) {
            $key = $this->quarterKey($log->getDate());
            if ($key === null) {
                continue;
            }
            $groups[$key][] = $log;
        }

        if ($groups === []) {
            return ['hasData' => false, 'quarters' => [], 'delta' => []];
        }

        ksort($groups);

        $quarters = [];
        foreach ($groups as $key => $runs) {
            $quarters[] = $this->buildQuarterRow($key, $runs);
        }

        return [
            'hasData' => true,
            'quarters' => $quarters,
            'delta' => $this->buildDelta($quarters),
        ];
    }

    /**
     * @param array<int, RunLog> $runs
     * @return array{key:string,label:string,runs:int,km:float,avgBpmEf:?int,avgPace:?string,avgPaceEf:?string,avgPaceTempo:?string,dplus:int,totalTime:?string}
     */
    private function buildQuarterRow(string $key, array $runs): array
    {
        $km = 0.0;
        $dplus = 0;
        $totalSec = 0;

        $efBpm = [];
        $paceAll = [];
        $paceEf = [];
        $paceTempo = [];

        foreach ($runs as $run) {
            $km += (float) ($run->getKm() ?? 0.0);
            $dplus += (int) ($run->getDplus() ?? 0);

            $dur = $this->durationToSeconds($run->getDuration());
            if ($dur !== null) {
                $totalSec += $dur;
            }

            $type = strtoupper((string) ($run->getRunType() ?? ''));
            $pace = $this->paceToSeconds($run->getAllure());

            if ($pace !== null) {
                $paceAll[] = $pace;
                if ($type === 'EF') {
                    $paceEf[] = $pace;
                } elseif ($type === 'TEMPO') {
                    $paceTempo[] = $pace;
                }
            }

            if ($type === 'EF' && $run->getBpm() !== null) {
                $efBpm[] = (int) $run->getBpm();
            }
        }

        return [
            'key' => $key,
            'label' => $this->quarterLabel($key),
            'runs' => count($runs),
            'km' => round($km, 1),
            'avgBpmEf' => $efBpm !== [] ? (int) round(array_sum($efBpm) / count($efBpm)) : null,
            'avgPace' => $this->avgPace($paceAll),
            'avgPaceEf' => $this->avgPace($paceEf),
            'avgPaceTempo' => $this->avgPace($paceTempo),
            'dplus' => $dplus,
            'totalTime' => $totalSec > 0 ? $this->secondsToHms($totalSec) : null,
        ];
    }

    /**
     * Global first->last quarter delta per indicator.
     * @param array<int, array<string,mixed>> $quarters
     * @return array<string, array{from:string, to:string, gap:string, trend:string}>
     */
    private function buildDelta(array $quarters): array
    {
        if (count($quarters) < 2) {
            return [];
        }

        $first = $quarters[0];
        $last = $quarters[count($quarters) - 1];
        $delta = [];

        // BPM EF: lower is better -> down arrow = progress.
        if ($first['avgBpmEf'] !== null && $last['avgBpmEf'] !== null) {
            $diff = $last['avgBpmEf'] - $first['avgBpmEf'];
            $delta['bpmEf'] = [
                'from' => $first['avgBpmEf'] . ' bpm',
                'to' => $last['avgBpmEf'] . ' bpm',
                'gap' => ($diff > 0 ? '+' : '') . $diff . ' bpm',
                'trend' => $diff < 0 ? self::DOWN : ($diff > 0 ? self::UP : self::FLAT),
            ];
        }

        // Pace: lower seconds/km is faster -> down arrow = progress.
        $firstPace = $this->paceToSeconds($first['avgPace']);
        $lastPace = $this->paceToSeconds($last['avgPace']);
        if ($firstPace !== null && $lastPace !== null) {
            $diff = $lastPace - $firstPace;
            $delta['pace'] = [
                'from' => (string) $first['avgPace'] . '/km',
                'to' => (string) $last['avgPace'] . '/km',
                'gap' => ($diff < 0 ? '-' : '+') . $this->secondsToMmSs(abs($diff)) . '/km',
                'trend' => $diff < 0 ? self::DOWN : ($diff > 0 ? self::UP : self::FLAT),
            ];
        }

        // Volume: higher km is more -> up arrow.
        $diffKm = round($last['km'] - $first['km'], 1);
        $delta['km'] = [
            'from' => $this->fmtKm($first['km']),
            'to' => $this->fmtKm($last['km']),
            'gap' => ($diffKm >= 0 ? '+' : '') . $this->fmtKm($diffKm),
            'trend' => $diffKm > 0 ? self::UP : ($diffKm < 0 ? self::DOWN : self::FLAT),
        ];

        return $delta;
    }

    /** @param array<int,int> $paces */
    private function avgPace(array $paces): ?string
    {
        if ($paces === []) {
            return null;
        }
        return $this->secondsToMmSs((int) round(array_sum($paces) / count($paces)));
    }

    private function quarterKey(string $dateYmd): ?string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $dateYmd, $m)) {
            return null;
        }
        $month = (int) $m[2];
        if ($month < 1 || $month > 12) {
            return null;
        }
        $quarter = intdiv($month - 1, 3) + 1;
        return $m[1] . '-Q' . $quarter;
    }

    private function quarterLabel(string $key): string
    {
        [$year, $q] = explode('-', $key);
        return $q . ' ' . $year;
    }

    private function fmtKm(float $km): string
    {
        return number_format($km, 1, '.', '') . ' km';
    }

    private function paceToSeconds(?string $pace): ?int
    {
        if ($pace !== null && str_contains($pace, ':')) {
            $parts = explode(':', $pace);
            if (count($parts) === 2) {
                $min = (int) $parts[0];
                $sec = (int) $parts[1];
                if ($min >= 0 && $sec >= 0 && $sec < 60) {
                    return $min * 60 + $sec;
                }
            }
        }
        return null;
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

    private function secondsToMmSs(int $seconds): string
    {
        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    private function secondsToHms(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        if ($h > 0) {
            return sprintf('%dh%02d', $h, $m);
        }
        return sprintf('%dmin', $m);
    }
}
