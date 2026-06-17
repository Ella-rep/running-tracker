<?php

namespace App\Service;

use App\Entity\Plan;
use App\Entity\RunLog;
use App\Repository\RunLogRepository;

/**
 * Builds a per-plan evolution recap: per-run-type BPM/pace comparison between
 * the first and the last month of the plan, plus a global plan summary.
 * Source: run logs linked to the plan (via plannedSession or date window).
 */
final class PlanEvolutionService
{
    /** Canonical display order for run types. */
    private const TYPE_ORDER = ['EF', 'SL', 'T', 'FRAC', 'RACE', 'RECUP'];

    private const TYPE_LABELS = [
        'EF' => 'en endurance (sorties EF)',
        'SL' => 'en sortie longue',
        'T' => 'en tempo',
        'FRAC' => 'en fractionné',
        'RACE' => 'en course',
        'RECUP' => 'en récupération',
    ];

    public function __construct(private RunLogRepository $runLogRepository)
    {
    }

    /**
     * @return array{
     *   hasData: bool,
     *   cards: array<int, array{code:string, typeLabel:string, bpm:?array, pace:?array}>,
     *   summary: ?array{runs:int, km:string, time:?string}
     * }
     */
    public function buildQuarterlyRecap(Plan $plan): array
    {
        $logs = $this->runLogRepository->findByPlan($plan);
        if ($logs === []) {
            return ['hasData' => false, 'cards' => [], 'summary' => null];
        }

        usort($logs, static fn (RunLog $a, RunLog $b): int => strcmp($a->getDate(), $b->getDate()));

        // "Month" = a 4-week (28-day) window: first 4 weeks vs last 4 weeks of the plan.
        $first = new \DateTimeImmutable(substr($logs[0]->getDate(), 0, 10));
        $last = new \DateTimeImmutable(substr($logs[count($logs) - 1]->getDate(), 0, 10));
        $startUntil = $first->modify('+27 days');
        $endFrom = $last->modify('-27 days');

        $startLogs = [];
        $endLogs = [];
        foreach ($logs as $log) {
            $d = new \DateTimeImmutable(substr($log->getDate(), 0, 10));
            if ($d <= $startUntil) {
                $startLogs[] = $log;
            }
            if ($d >= $endFrom) {
                $endLogs[] = $log;
            }
        }

        return [
            'hasData' => true,
            'cards' => $this->buildCards($this->perType($startLogs), $this->perType($endLogs)),
            'summary' => $this->buildSummary($logs),
        ];
    }

    /**
     * One card per run type, each carrying its BPM and pace evolution.
     * @param array<string, array{bpm:?int, pace:?string}> $start
     * @param array<string, array{bpm:?int, pace:?string}> $end
     * @return array<int, array{code:string, typeLabel:string, bpm:?array, pace:?array}>
     */
    private function buildCards(array $start, array $end): array
    {
        $order = array_values(array_unique(array_merge(
            self::TYPE_ORDER,
            array_keys($start),
            array_keys($end)
        )));

        $cards = [];
        foreach ($order as $code) {
            if ($code === 'RACE') {
                continue; // la course/objectif n'entre pas dans la progression
            }
            $bpmFrom = $start[$code]['bpm'] ?? null;
            $bpmTo = $end[$code]['bpm'] ?? null;
            $paceFrom = $start[$code]['pace'] ?? null;
            $paceTo = $end[$code]['pace'] ?? null;

            $hasBpm = $bpmFrom !== null || $bpmTo !== null;
            $hasPace = $paceFrom !== null || $paceTo !== null;
            if (!$hasBpm && !$hasPace) {
                continue;
            }

            $cards[] = [
                'code' => $code,
                'typeLabel' => $this->typeLabel($code),
                'bpm' => $hasBpm ? $this->bpmMetric($bpmFrom, $bpmTo) : null,
                'pace' => $hasPace ? $this->paceMetric($paceFrom, $paceTo) : null,
            ];
        }

        return $cards;
    }

    /**
     * @return array{from:string, to:string, gap:?string, trend:?string, improved:?bool}
     */
    private function bpmMetric(?int $from, ?int $to): array
    {
        $gap = $trend = null;
        $improved = null;
        if ($from !== null && $to !== null) {
            $diff = $to - $from;
            $gap = ($diff > 0 ? '+' : '') . $diff . ' bpm';
            $trend = $diff < 0 ? 'down' : ($diff > 0 ? 'up' : 'flat');
            $improved = $diff < 0; // lower HR = progress
        }

        return [
            'from' => $from !== null ? $from . ' bpm' : '—',
            'to' => $to !== null ? $to . ' bpm' : '—',
            'gap' => $gap,
            'trend' => $trend,
            'improved' => $improved,
        ];
    }

    /**
     * @return array{from:string, to:string, gap:?string, trend:?string, improved:?bool}
     */
    private function paceMetric(?string $from, ?string $to): array
    {
        $gap = $trend = null;
        $improved = null;
        $fromSec = $this->paceToSeconds($from);
        $toSec = $this->paceToSeconds($to);
        if ($fromSec !== null && $toSec !== null) {
            $diff = $toSec - $fromSec;
            $gap = ($diff < 0 ? '-' : '+') . $this->secondsToMmSs(abs($diff)) . '/km';
            $trend = $diff < 0 ? 'down' : ($diff > 0 ? 'up' : 'flat');
            $improved = $diff < 0; // faster = progress
        }

        return [
            'from' => $from !== null ? $from . '/km' : '—',
            'to' => $to !== null ? $to . '/km' : '—',
            'gap' => $gap,
            'trend' => $trend,
            'improved' => $improved,
        ];
    }

    /**
     * Average BPM and pace per run type for a set of logs.
     * @param array<int, RunLog> $logs
     * @return array<string, array{bpm:?int, pace:?string}>
     */
    private function perType(array $logs): array
    {
        $bucket = [];
        foreach ($logs as $log) {
            $code = $this->normalizeType((string) ($log->getRunType() ?? ''));
            if ($code === null) {
                continue;
            }
            $bucket[$code] ??= ['bpm' => [], 'pace' => []];

            $pace = $this->paceToSeconds($log->getAllure());
            if ($pace !== null) {
                $bucket[$code]['pace'][] = $pace;
            }
            if ($log->getBpm() !== null) {
                $bucket[$code]['bpm'][] = (int) $log->getBpm();
            }
        }

        $out = [];
        foreach ($bucket as $code => $d) {
            $out[$code] = [
                'bpm' => $d['bpm'] !== [] ? (int) round(array_sum($d['bpm']) / count($d['bpm'])) : null,
                'pace' => $this->avgPace($d['pace']),
            ];
        }
        return $out;
    }

    /**
     * @param array<int, RunLog> $logs
     * @return array{runs:int, km:string, time:?string}
     */
    private function buildSummary(array $logs): array
    {
        $km = 0.0;
        $totalSec = 0;
        foreach ($logs as $log) {
            $km += (float) ($log->getKm() ?? 0.0);
            $dur = $this->durationToSeconds($log->getDuration());
            if ($dur !== null) {
                $totalSec += $dur;
            }
        }

        return [
            'runs' => count($logs),
            'km' => number_format(round($km, 1), 1, '.', ''),
            'time' => $totalSec > 0 ? $this->secondsToHms($totalSec) : null,
        ];
    }

    private function typeLabel(string $code): string
    {
        return self::TYPE_LABELS[$code] ?? ('en ' . strtolower($code));
    }

    private function normalizeType(string $raw): ?string
    {
        $compact = strtoupper(trim((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $raw)));
        $compact = preg_replace('/\s+/', ' ', $compact) ?? $compact;
        if ($compact === '') {
            return null;
        }
        if ($compact === 'EF' || str_contains($compact, 'ENDURANCE FONDAMENTALE') || $compact === 'ENDURANCE') {
            return 'EF';
        }
        if ($compact === 'SL' || str_contains($compact, 'SORTIE LONGUE')) {
            return 'SL';
        }
        if ($compact === 'FL' || $compact === 'FC' || $compact === 'VMA' || $compact === 'SEUIL' || str_contains($compact, 'FRACTIONNE')) {
            return 'FRAC';
        }
        if ($compact === 'T' || str_contains($compact, 'TEMPO')) {
            return 'T';
        }
        if ($compact === 'RACE' || str_contains($compact, 'COURSE')) {
            return 'RACE';
        }
        if ($compact === 'RECUP' || str_contains($compact, 'RECUPERATION')) {
            return 'RECUP';
        }
        return $compact;
    }

    /** @param array<int,int> $paces */
    private function avgPace(array $paces): ?string
    {
        if ($paces === []) {
            return null;
        }
        return $this->secondsToMmSs((int) round(array_sum($paces) / count($paces)));
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
