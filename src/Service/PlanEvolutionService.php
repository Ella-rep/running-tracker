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
    private const TYPE_ORDER = ['EF', 'SL', 'T', 'FL', 'FC', 'RACE', 'RECUP'];

    private const TYPE_LABELS = [
        'EF' => 'en endurance (sorties EF)',
        'SL' => 'en sortie longue',
        'T' => 'en tempo',
        'FL' => 'en fractionné long',
        'FC' => 'en fractionné court',
        'RACE' => 'en course',
        'RECUP' => 'en récupération',
    ];

    public function __construct(private RunLogRepository $runLogRepository)
    {
    }

    /**
     * @return array{
     *   hasData: bool,
     *   cards: array<int, array{metric:string, title:string, typeLabel:string, from:string, to:string, gap:?string, trend:?string, improved:?bool}>,
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

        // Group by calendar month (YYYY-MM); compare first vs last month.
        $months = [];
        foreach ($logs as $log) {
            $key = substr($log->getDate(), 0, 7);
            $months[$key][] = $log;
        }
        ksort($months);
        $keys = array_keys($months);
        $startLogs = $months[$keys[0]];
        $endLogs = $months[$keys[count($keys) - 1]];

        return [
            'hasData' => true,
            'cards' => $this->buildCards($this->perType($startLogs), $this->perType($endLogs)),
            'summary' => $this->buildSummary($logs),
        ];
    }

    /**
     * @param array<string, array{bpm:?int, pace:?string}> $start
     * @param array<string, array{bpm:?int, pace:?string}> $end
     * @return array<int, array{metric:string, title:string, typeLabel:string, from:string, to:string, gap:?string, trend:?string, improved:?bool}>
     */
    private function buildCards(array $start, array $end): array
    {
        $order = array_values(array_unique(array_merge(
            self::TYPE_ORDER,
            array_keys($start),
            array_keys($end)
        )));

        $cards = [];

        // BPM cards first, then pace cards (both following the canonical order).
        foreach ($order as $code) {
            $from = $start[$code]['bpm'] ?? null;
            $to = $end[$code]['bpm'] ?? null;
            if ($from === null && $to === null) {
                continue;
            }
            $cards[] = $this->bpmCard($code, $from, $to);
        }
        foreach ($order as $code) {
            $from = $start[$code]['pace'] ?? null;
            $to = $end[$code]['pace'] ?? null;
            if ($from === null && $to === null) {
                continue;
            }
            $cards[] = $this->paceCard($code, $from, $to);
        }

        return $cards;
    }

    /**
     * @return array{metric:string, title:string, typeLabel:string, from:string, to:string, gap:?string, trend:?string, improved:?bool}
     */
    private function bpmCard(string $code, ?int $from, ?int $to): array
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
            'metric' => 'bpm',
            'title' => 'FC moyenne',
            'typeLabel' => $this->typeLabel($code),
            'from' => $from !== null ? $from . ' bpm' : '—',
            'to' => $to !== null ? $to . ' bpm' : '—',
            'gap' => $gap,
            'trend' => $trend,
            'improved' => $improved,
        ];
    }

    /**
     * @return array{metric:string, title:string, typeLabel:string, from:string, to:string, gap:?string, trend:?string, improved:?bool}
     */
    private function paceCard(string $code, ?string $from, ?string $to): array
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
            'metric' => 'pace',
            'title' => 'Allure moyenne',
            'typeLabel' => $this->typeLabel($code),
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
        if ($compact === 'FL' || str_contains($compact, 'FRACTIONNE LONG') || $compact === 'SEUIL') {
            return 'FL';
        }
        if ($compact === 'FC' || str_contains($compact, 'FRACTIONNE COURT') || $compact === 'VMA' || str_contains($compact, 'FRACTIONNE')) {
            return 'FC';
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
