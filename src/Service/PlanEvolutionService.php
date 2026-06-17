<?php

namespace App\Service;

use App\Entity\Plan;
use App\Entity\RunLog;
use App\Repository\RunLogRepository;

/**
 * Builds a per-plan evolution recap: a side-by-side comparison between the
 * first two weeks and the last two weeks of the plan's run logs.
 * Source: run logs linked to the plan (via plannedSession or date window).
 */
final class PlanEvolutionService
{
    /** Comparison window length, in days (2 weeks). */
    private const WINDOW_DAYS = 14;

    public function __construct(private RunLogRepository $runLogRepository)
    {
    }

    /**
     * @return array{
     *   hasData: bool,
     *   rows: array<int, array{label:string, from:string, to:string}>
     * }
     */
    public function buildQuarterlyRecap(Plan $plan): array
    {
        $logs = $this->runLogRepository->findByPlan($plan);
        if ($logs === []) {
            return ['hasData' => false, 'rows' => []];
        }

        usort($logs, static fn (RunLog $a, RunLog $b): int => strcmp($a->getDate(), $b->getDate()));

        $firstDate = $logs[0]->getDate();
        $lastDate = $logs[count($logs) - 1]->getDate();

        $startRuns = $this->runsBetween($logs, $firstDate, $this->shiftDays($firstDate, self::WINDOW_DAYS - 1));
        $endRuns = $this->runsBetween($logs, $this->shiftDays($lastDate, -(self::WINDOW_DAYS - 1)), $lastDate);

        if ($startRuns === [] && $endRuns === []) {
            return ['hasData' => false, 'rows' => []];
        }

        $start = $this->buildAggregate($startRuns);
        $end = $this->buildAggregate($endRuns);

        return [
            'hasData' => true,
            'rows' => $this->buildRows($start, $end),
        ];
    }

    /**
     * @param array{runs:int,km:float,avgBpmEf:?int,avgPace:?string,avgPaceEf:?string,avgPaceTempo:?string,dplus:int,totalTime:?string} $start
     * @param array{runs:int,km:float,avgBpmEf:?int,avgPace:?string,avgPaceEf:?string,avgPaceTempo:?string,dplus:int,totalTime:?string} $end
     * @return array<int, array{label:string, from:string, to:string}>
     */
    private function buildRows(array $start, array $end): array
    {
        return [
            ['label' => 'Sorties', 'from' => (string) $start['runs'], 'to' => (string) $end['runs']],
            ['label' => 'Volume', 'from' => $this->fmtKm($start['km']), 'to' => $this->fmtKm($end['km'])],
            ['label' => 'BPM EF', 'from' => $this->fmtBpm($start['avgBpmEf']), 'to' => $this->fmtBpm($end['avgBpmEf'])],
            ['label' => 'Allure', 'from' => $this->fmtPace($start['avgPace']), 'to' => $this->fmtPace($end['avgPace'])],
            ['label' => 'Allure EF', 'from' => $this->fmtPace($start['avgPaceEf']), 'to' => $this->fmtPace($end['avgPaceEf'])],
            ['label' => 'Allure tempo', 'from' => $this->fmtPace($start['avgPaceTempo']), 'to' => $this->fmtPace($end['avgPaceTempo'])],
            ['label' => 'D+', 'from' => $start['dplus'] . ' m', 'to' => $end['dplus'] . ' m'],
            ['label' => 'Temps total couru', 'from' => $start['totalTime'] ?? '—', 'to' => $end['totalTime'] ?? '—'],
        ];
    }

    /**
     * @param array<int, RunLog> $runs
     * @return array{runs:int,km:float,avgBpmEf:?int,avgPace:?string,avgPaceEf:?string,avgPaceTempo:?string,dplus:int,totalTime:?string}
     */
    private function buildAggregate(array $runs): array
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
     * @param array<int, RunLog> $logs
     * @return array<int, RunLog>
     */
    private function runsBetween(array $logs, string $from, string $to): array
    {
        return array_values(array_filter(
            $logs,
            static fn (RunLog $r): bool => $r->getDate() >= $from && $r->getDate() <= $to
        ));
    }

    private function shiftDays(string $dateYmd, int $days): string
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $dateYmd);
        if ($date === false) {
            return $dateYmd;
        }
        return $date->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    private function fmtBpm(?int $bpm): string
    {
        return $bpm !== null ? $bpm . ' bpm' : '—';
    }

    private function fmtPace(?string $pace): string
    {
        return $pace !== null ? $pace . '/km' : '—';
    }

    /** @param array<int,int> $paces */
    private function avgPace(array $paces): ?string
    {
        if ($paces === []) {
            return null;
        }
        return $this->secondsToMmSs((int) round(array_sum($paces) / count($paces)));
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
