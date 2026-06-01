<?php

namespace App\Service;

use App\Entity\RunLog;

/**
 * Computes EF-specific dashboard metrics and charts.
 */
final class DashboardEfMetricsService
{
    private const COLOR_TEXT_MUTED = 'var(--text-muted)';
    private const COLOR_Z1 = 'var(--z1)';
    private const COLOR_ACCENT3 = 'var(--accent3)';
    private const EF_EMPTY_MESSAGE = 'Pas encore assez de sorties EF avec BPM enregistre (minimum 2).';

    /**
     * @param array<int, RunLog> $logs
     * @return array<string,mixed>
     */
    public function buildEfSection(array $logs): array
    {
        $efRuns = $this->collectEfRuns($logs);
        if (count($efRuns) < 2) {
            return $this->emptyEfSection();
        }

        $points = $this->collectEfChartPoints($logs);
        if ($points === []) {
            return $this->emptyEfSection();
        }

        $chart = $this->buildEfChartSeries($points);

        return [
            'hasData' => true,
            'emptyMessage' => '',
            'chart' => [
                'paceTicks' => $chart['paceTicks'],
                'bpmTicks' => $chart['bpmTicks'],
                'pacePoints' => $chart['pacePoints'],
                'bpmPoints' => $chart['bpmPoints'],
                'efDots' => $chart['efDots'],
            ],
            'tableRows' => $this->buildEfTableRows($efRuns),
            'efBpmTrend' => $this->buildEfBpmTrend($efRuns),
            'meta' => 'Indice aérobie = allure (sec/km) ÷ BPM · Plus il est bas, meilleure est ton efficacité aérobie à effort constant',
        ];
    }

    /**
     * @param array<int, RunLog> $logs
     * @return array{items: array<int, array{label:string,value:string,valueColor:string,meta:string}>, emptyMessage:string}
     */
    public function buildEfKpis(array $logs): array
    {
        $efRuns = array_values(array_filter($logs, function (RunLog $log): bool {
            $runType = strtoupper((string) ($log->getRunType() ?? ''));
            return $runType === 'EF' && $log->getBpm() !== null && $this->paceToSeconds($log->getAllure()) !== null && $log->getDate() !== '';
        }));

        usort($efRuns, static fn (RunLog $a, RunLog $b) => strcmp($a->getDate(), $b->getDate()));

        if (count($efRuns) < 2) {
            return [
                'items' => [],
                'emptyMessage' => self::EF_EMPTY_MESSAGE,
            ];
        }

        $first = $efRuns[0];
        $last = $efRuns[count($efRuns) - 1];

        $firstPace = $this->paceToSeconds($first->getAllure()) ?? 0;
        $lastPace = $this->paceToSeconds($last->getAllure()) ?? 0;
        // Positive pace delta means faster recent EF pace.
        $paceDelta = $firstPace - $lastPace;

        // Aerobic index = pace seconds per km divided by BPM (lower is better).
        $firstIdx = round(($firstPace / max(1, (int) $first->getBpm())) * 100) / 100;
        $lastIdx = round(($lastPace / max(1, (int) $last->getBpm())) * 100) / 100;
        $idxDelta = $firstIdx - $lastIdx;

        // Mean BPM over EF sample.
        $avgBpm = (int) round(array_sum(array_map(static fn (RunLog $r): int => (int) $r->getBpm(), $efRuns)) / count($efRuns));

        $paceSign = $paceDelta >= 0 ? '↗' : '↘';
        $paceColor = $paceDelta >= 0 ? self::COLOR_Z1 : self::COLOR_ACCENT3;
        $idxSign = $idxDelta >= 0 ? '↗' : '↘';
        $idxColor = $idxDelta >= 0 ? self::COLOR_Z1 : self::COLOR_ACCENT3;
        $paceStr = substr($this->secondsToDuration((int) abs($paceDelta)), 3);

        return [
            'items' => [
                [
                    'label' => 'Gain d\'allure EF',
                    'value' => sprintf('%s %s/km', $paceSign, $paceStr),
                    'valueColor' => $paceColor,
                    'meta' => sprintf('%s → %s /km', (string) $first->getAllure(), (string) $last->getAllure()),
                ],
                [
                    'label' => 'BPM moyen EF',
                    'value' => sprintf('%d bpm', $avgBpm),
                    'valueColor' => 'var(--accent2)',
                    'meta' => sprintf('sur %d sorties EF', count($efRuns)),
                ],
                [
                    'label' => 'Indice aérobie',
                    'value' => sprintf('%s %.2f', $idxSign, abs($idxDelta)),
                    'valueColor' => $idxColor,
                    'meta' => sprintf('%s/km @ %dbpm → %s/km @ %dbpm', (string) $first->getAllure(), (int) $first->getBpm(), (string) $last->getAllure(), (int) $last->getBpm()),
                ],
            ],
            'emptyMessage' => '',
        ];
    }

    /** @return array<string,mixed> */
    private function emptyEfSection(): array
    {
        return [
            'hasData' => false,
            'emptyMessage' => self::EF_EMPTY_MESSAGE,
            'chart' => ['paceTicks' => [], 'bpmTicks' => [], 'pacePoints' => [], 'bpmPoints' => [], 'efDots' => []],
            'tableRows' => [],
            'meta' => '',
        ];
    }

    /** @param array<int,RunLog> $logs @return array<int,RunLog> */
    private function collectEfRuns(array $logs): array
    {
        $efRuns = array_values(array_filter($logs, function (RunLog $log): bool {
            $type = strtoupper((string) ($log->getRunType() ?? ''));
            return $type === 'EF' && $log->getBpm() !== null && $this->paceToSeconds($log->getAllure()) !== null && $log->getDate() !== '';
        }));
        usort($efRuns, static fn (RunLog $a, RunLog $b) => strcmp($a->getDate(), $b->getDate()));
        return $efRuns;
    }

    /** @param array<int,RunLog> $logs @return array<int,array{x:int,pace:int,bpm:?int,isEf:bool}> */
    private function collectEfChartPoints(array $logs): array
    {
        $efAll = array_values(array_filter($logs, function (RunLog $log): bool {
            return $log->getDate() !== '' && $this->paceToSeconds($log->getAllure()) !== null && strtoupper((string) ($log->getRunType() ?? '')) !== 'RACE';
        }));
        usort($efAll, static fn (RunLog $a, RunLog $b) => strcmp($a->getDate(), $b->getDate()));

        $points = [];
        foreach ($efAll as $idx => $run) {
            $pace = $this->paceToSeconds($run->getAllure());
            if ($pace === null) {
                continue;
            }
            $points[] = [
                'x' => $idx,
                'pace' => $pace,
                'bpm' => $run->getBpm(),
                'isEf' => strtoupper((string) ($run->getRunType() ?? '')) === 'EF' && $run->getBpm() !== null,
            ];
        }

        return $points;
    }

    /** @param array<int,array{x:int,pace:int,bpm:?int,isEf:bool}> $points @return array<string,mixed> */
    private function buildEfChartSeries(array $points): array
    {
        $paces = array_map(static fn (array $p): int => (int) $p['pace'], $points);
        // Expand bounds a bit to avoid clipping chart edges.
        $minP = min($paces) - 10;
        $maxP = max($paces) + 10;
        $paceRange = max(1, $maxP - $minP);

        $bpms = array_values(array_map(static fn (array $p): int => (int) $p['bpm'], array_filter($points, static fn (array $p): bool => $p['bpm'] !== null)));
        $minB = $bpms !== [] ? min($bpms) - 5 : 130;
        $maxB = $bpms !== [] ? max($bpms) + 5 : 160;
        $bpmRange = max(1, $maxB - $minB);

        $count = count($points);
        $den = max(1, $count - 1);
        $pacePoints = [];
        $bpmPoints = [];
        $efDots = [];

        foreach ($points as $i => $p) {
            $x = $i / $den;
            // Normalize pace/BPM values to [0..1] for SVG plotting.
            $paceY = 1 - (($p['pace'] - $minP) / $paceRange);
            $pacePoints[] = ['x' => round($x, 6), 'y' => round($paceY, 6)];

            if ($p['bpm'] !== null) {
                $bpmY = 1 - ((((int) $p['bpm']) - $minB) / $bpmRange);
                $bpmPoints[] = ['x' => round($x, 6), 'y' => round($bpmY, 6)];
                if ($p['isEf']) {
                    $efDots[] = ['x' => round($x, 6), 'paceY' => round($paceY, 6), 'bpmY' => round($bpmY, 6)];
                }
            }
        }

        $paceTicks = [];
        foreach ([0.0, 0.25, 0.5, 0.75, 1.0] as $t) {
            $pVal = (int) round($minP + (1 - $t) * ($maxP - $minP));
            $paceTicks[] = ['t' => $t, 'label' => $this->secondsToMmSs($pVal)];
        }

        $bpmTicks = [];
        if ($bpms !== []) {
            foreach ([0.0, 0.5, 1.0] as $t) {
                $bVal = (int) round($minB + $t * ($maxB - $minB));
                $bpmTicks[] = ['t' => $t, 'label' => (string) $bVal];
            }
        }

        return [
            'paceTicks' => $paceTicks,
            'bpmTicks' => $bpmTicks,
            'pacePoints' => $pacePoints,
            'bpmPoints' => $bpmPoints,
            'efDots' => $efDots,
        ];
    }

    /** @param array<int,RunLog> $efRuns @return array<int,array{date:string,bpm:int,avg3:?float}> */
    private function buildEfBpmTrend(array $efRuns): array
    {
        $efBpmTrend = [];
        $bpmWindow = [];

        foreach ($efRuns as $run) {
            $bpmVal = (int) $run->getBpm();
            $bpmWindow[] = $bpmVal;
            if (count($bpmWindow) > 3) {
                array_shift($bpmWindow);
            }
            // Rolling average over up to 3 latest EF runs.
            $avg3 = count($bpmWindow) >= 2 ? round(array_sum($bpmWindow) / count($bpmWindow), 1) : null;
            $efBpmTrend[] = ['date' => $run->getDate(), 'bpm' => $bpmVal, 'avg3' => $avg3];
        }

        return $efBpmTrend;
    }

    /** @param array<int,RunLog> $efRuns @return array<int,array<string,string>> */
    private function buildEfTableRows(array $efRuns): array
    {
        $tableRows = [];
        $prevIdx = null;

        foreach ($efRuns as $i => $run) {
            $pace = $this->paceToSeconds($run->getAllure()) ?? 0;
            // Aerobic index per run (sec/km per bpm).
            $idx = round(($pace / max(1, (int) $run->getBpm())) * 100) / 100;
            [$trendLabel, $trendColor] = $this->resolveEfTrend($idx, $prevIdx);
            $idxColor = $this->resolveEfIndexColor($efRuns, $i, $idx);

            $tableRows[] = [
                'date' => $run->getDate(),
                'km' => $run->getKm() !== null ? number_format((float) $run->getKm(), 1, '.', '') : '—',
                'bpm' => $run->getBpm() !== null ? ((string) $run->getBpm()) . ' bpm' : '—',
                'allure' => (string) $run->getAllure() . '/km',
                'idx' => number_format((float) $idx, 2, '.', ''),
                'idxColor' => $idxColor,
                'trendLabel' => $trendLabel,
                'trendColor' => $trendColor,
            ];

            $prevIdx = $idx;
        }

        return $tableRows;
    }

    /** @return array{0:string,1:string} */
    private function resolveEfTrend(float $idx, ?float $prevIdx): array
    {
        $trend = ['—', self::COLOR_TEXT_MUTED];
        // 0.05 guard band avoids noisy up/down trend switching.
        if ($prevIdx !== null && $idx < $prevIdx - 0.05) {
            $trend = ['↗ mieux', '#4ade80'];
        } elseif ($prevIdx !== null && $idx > $prevIdx + 0.05) {
            $trend = ['↘ moins bien', '#e05580'];
        } elseif ($prevIdx !== null) {
            $trend = ['→ stable', self::COLOR_TEXT_MUTED];
        }
        return $trend;
    }

    /** @param array<int,RunLog> $efRuns */
    private function resolveEfIndexColor(array $efRuns, int $index, float $idx): string
    {
        $color = self::COLOR_TEXT_MUTED;
        if ($index > 0) {
            $prevRunPace = $this->paceToSeconds($efRuns[$index - 1]->getAllure()) ?? 0;
            $prevRunIdx = round(($prevRunPace / max(1, (int) $efRuns[$index - 1]->getBpm())) * 100) / 100;
            // Same threshold as trend labels to keep color and text consistent.
            if ($idx < $prevRunIdx - 0.05) {
                $color = self::COLOR_Z1;
            } elseif ($idx > $prevRunIdx + 0.05) {
                $color = self::COLOR_ACCENT3;
            }
        }
        return $color;
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
}
