<?php

namespace App\Service;

use App\Entity\Plan;
use App\Entity\RunLog;
use App\Entity\User;
use App\Repository\PlanDetailsRepository;
use App\Repository\PlanRepository;
use App\Repository\RunLogRepository;

/**
 * Handles dashboard advanced computations: plan widgets, training load and coherence alerts.
 */
final class DashboardAdvancedMetricsService
{
    private const COLOR_TEXT_MUTED = 'var(--text-muted)';
    private const COLOR_Z1 = 'var(--z1)';
    private const COLOR_ACCENT3 = 'var(--accent3)';
    private const TITLE_PACE_PROGRESSION = 'Progression allure';
    private const MONTH_NAMES = [
        1 => 'janvier', 2 => 'fevrier', 3 => 'mars', 4 => 'avril', 5 => 'mai', 6 => 'juin',
        7 => 'juillet', 8 => 'aout', 9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'decembre',
    ];

    public function __construct(
        private RunLogRepository $runLogs,
        private PlanRepository $plans,
        private PlanDetailsRepository $planDetails,
    ) {
    }

    /**
     * @return array{progress:array<string,mixed>,calendar:array<string,mixed>}
     */
    public function buildPlanWidgets(User $user): array
    {
        $selection = $this->selectTargetPlan($user);
        $targetPlan = $selection['targetPlan'];
        $isExample = $selection['isExample'];
        $today = (new \DateTimeImmutable('today'))->setTime(0, 0, 0);

        if (!$targetPlan instanceof Plan) {
            $monthStart = $today->modify('first day of this month')->setTime(0, 0, 0);

            return [
                'progress' => [
                    'title' => 'Progression du plan exemple',
                    'done' => 0,
                    'total' => 0,
                    'pct' => 0,
                ],
                'calendar' => $this->buildPlanCalendar(
                    $monthStart,
                    $today,
                    [],
                    'Aucune seance programmee ce mois-ci',
                    'Ajoute un plan avec des dates de seances pour remplir ce calendrier.'
                ),
            ];
        }

        $rows = $this->planDetails->findBy(['user' => $user, 'plan' => $targetPlan], ['position' => 'ASC']);
        $total = count($rows);
        $loggedDetailIds = $this->runLogs->findLoggedDetailIds($user);
        $aggregates = $this->aggregatePlanRows($rows, $loggedDetailIds);

        $done = $aggregates['done'];
        $datedRows = $aggregates['datedRows'];
        $itemsByDate = $aggregates['itemsByDate'];

        usort($datedRows, static fn (\DateTimeImmutable $left, \DateTimeImmutable $right): int => $left <=> $right);

        $monthStart = $this->resolveVisibleMonthStart($today, $datedRows);
        $monthKey = $monthStart->format('Y-m');
        $visibleDates = array_filter($datedRows, static fn (\DateTimeImmutable $sessionDate): bool => $sessionDate->format('Y-m') === $monthKey);
        $visibleCount = count($visibleDates);
        $progressTitle = $isExample
            ? 'Progression du plan exemple'
            : sprintf('Progression du plan %s', $targetPlan->getName());
        $summary = 'Aucune seance programmee ce mois-ci';
        if ($visibleCount > 0) {
            $pluralSuffix = $visibleCount > 1 ? 's' : '';
            $summary = sprintf('%d seance%s programmee%s', $visibleCount, $pluralSuffix, $pluralSuffix);
        }

        return [
            'progress' => [
                'title' => $progressTitle,
                'done' => $done,
                'total' => $total,
                'pct' => $total > 0 ? (int) round(($done / $total) * 100) : 0,
            ],
            'calendar' => $this->buildPlanCalendar(
                $monthStart,
                $today,
                $itemsByDate,
                $summary,
                $datedRows === [] ? 'Ajoute des dates a ton plan pour voir les seances sur le calendrier.' : ''
            ),
        ];
    }

    /**
     * @param array<int, RunLog> $logs
     * @return array{hasData:bool,statusKey:string,statusLabel:string,statusColor:string,acute:float,chronic:float,ratio:float|null,deltaPct:int,recommendation:string,weekly:array<int,array{label:string,load:float}>}
     */
    public function buildTrainingLoad(array $logs): array
    {
        $dailyLoads = $this->buildDailyLoads($logs);

        if (empty($dailyLoads)) {
            return [
                'hasData' => false,
                'statusKey' => 'none',
                'statusLabel' => 'Pas de donnees',
                'statusColor' => self::COLOR_TEXT_MUTED,
                'acute' => 0.0,
                'chronic' => 0.0,
                'ratio' => null,
                'deltaPct' => 0,
                'recommendation' => 'Ajoute quelques sorties pour activer le suivi de charge.',
                'weekly' => [],
            ];
        }

        $today = (new \DateTimeImmutable('now'))->setTime(0, 0, 0);
        $acwr = $this->computeAcwrLoads($dailyLoads, $today);
        $acute = $acwr['acute'];
        $chronicTotal = $acwr['chronicTotal'];

        $chronic = $chronicTotal / 4.0;
        $ratio = $chronic > 0 ? round($acute / $chronic, 2) : null;
        $deltaPct = $chronic > 0 ? (int) round((($acute - $chronic) / $chronic) * 100) : 0;
        $status = $this->resolveTrainingLoadStatus($ratio);
        $weekly = $this->buildWeeklyLoadTrend($dailyLoads, $today);

        return [
            'hasData' => true,
            'statusKey' => $status['key'],
            'statusLabel' => $status['label'],
            'statusColor' => $status['color'],
            'acute' => round($acute, 1),
            'chronic' => round($chronic, 1),
            'ratio' => $ratio,
            'deltaPct' => $deltaPct,
            'recommendation' => $status['recommendation'],
            'weekly' => $weekly,
        ];
    }

    /**
     * @param array<int, RunLog> $logs
     * @return array<int,array{ok:bool,title:string,msg:string}>
     */
    public function buildCoherenceAlerts(array $logs): array
    {
        $alerts = [];

        $this->appendPaceProgressAlert($alerts, $logs);
        $this->appendEfBpmAlert($alerts, $logs);
        $this->appendTrainingGapAlert($alerts, $logs);

        if (empty($alerts)) {
            $alerts[] = ['ok' => true, 'title' => 'Analyse indisponible', 'msg' => 'Pas assez de donnees pour etablir des indicateurs de coherence.'];
        }

        return $alerts;
    }

    /** @return array{targetPlan:?Plan,isExample:bool} */
    private function selectTargetPlan(User $user): array
    {
        $plans = $this->plans->findBy(['user' => $user], ['id' => 'ASC']);
        $selection = array_reduce($plans, static function (array $carry, Plan $plan): array {
            if ($plan->getName() === 'starter') {
                $carry['starter'] = $plan;
                return $carry;
            }
            $carry['latest'] = $plan;
            return $carry;
        }, ['latest' => null, 'starter' => null]);

        return [
            'targetPlan' => $selection['latest'] ?? $selection['starter'],
            'isExample' => $selection['latest'] === null && $selection['starter'] instanceof Plan,
        ];
    }

    /** @param array<int,mixed> $rows @param array<int,mixed> $loggedDetailIds @return array{done:int,datedRows:array<int,\DateTimeImmutable>,itemsByDate:array<string,array<int,array<string,mixed>>>} */
    private function aggregatePlanRows(array $rows, array $loggedDetailIds): array
    {
        return array_reduce($rows, static function (array $carry, $row) use ($loggedDetailIds): array {
            $carry['done'] += $row->isDone() ? 1 : 0;
            $date = $row->getSessionDate();
            if (!$date instanceof \DateTimeInterface) {
                return $carry;
            }
            $sessionDate = \DateTimeImmutable::createFromInterface($date)->setTime(0, 0, 0);
            $dateKey = $sessionDate->format('Y-m-d');
            $carry['datedRows'][] = $sessionDate;
            $carry['itemsByDate'][$dateKey] ??= [];
            $carry['itemsByDate'][$dateKey][] = [
                'kind' => 'session',
                'detailId' => $row->getId(),
                'planId' => $row->getPlan()->getId(),
                'sessionType' => $row->getSessionType(),
                'label' => sprintf('Seance %d', $row->getPosition()),
                'format' => $row->getFormat(),
                'pe' => $row->getPe(),
                'isDone' => $row->isDone(),
                'hasLog' => isset($loggedDetailIds[$row->getId()]),
                'isOptional' => $row->isOptional(),
            ];
            return $carry;
        }, ['done' => 0, 'datedRows' => [], 'itemsByDate' => []]);
    }

    /** @param array<int,\DateTimeImmutable> $datedRows */
    private function resolveVisibleMonthStart(\DateTimeImmutable $today, array $datedRows): \DateTimeImmutable
    {
        $monthStart = $today->modify('first day of this month')->setTime(0, 0, 0);
        $currentMonthKey = $monthStart->format('Y-m');
        $datedMonthKeys = array_values(array_unique(array_map(static fn (\DateTimeImmutable $sessionDate): string => $sessionDate->format('Y-m'), $datedRows)));
        $futureDates = array_values(array_filter($datedRows, static fn (\DateTimeImmutable $sessionDate): bool => $sessionDate >= $today));
        $visibleMonthKey = $currentMonthKey;
        if (!in_array($currentMonthKey, $datedMonthKeys, true)) {
            $visibleMonthKey = $futureDates[0]->format('Y-m') ?? ($datedMonthKeys[0] ?? $currentMonthKey);
        }
        return $visibleMonthKey === $currentMonthKey ? $monthStart : new \DateTimeImmutable($visibleMonthKey . '-01');
    }

    /** @param array<string,array<int,array<string,mixed>>> $itemsByDate @return array<string,mixed> */
    private function buildPlanCalendar(\DateTimeImmutable $monthStart, \DateTimeImmutable $today, array $itemsByDate, string $summary, string $emptyMessage): array
    {
        $calendarStart = $monthStart->modify(sprintf('-%d days', (int) $monthStart->format('N') - 1));
        $days = [];
        for ($i = 0; $i < 42; $i++) {
            $dayDate = $calendarStart->modify(sprintf('+%d days', $i));
            $dayKey = $dayDate->format('Y-m-d');
            $days[] = [
                'date' => $dayKey,
                'day' => (int) $dayDate->format('j'),
                'inMonth' => $dayDate->format('Y-m') === $monthStart->format('Y-m'),
                'isToday' => $dayDate->format('Y-m-d') === $today->format('Y-m-d'),
                'items' => $itemsByDate[$dayKey] ?? [],
            ];
        }
        $monthNumber = (int) $monthStart->format('n');
        return [
            'title' => 'Calendrier des seances prevues',
            'monthKey' => $monthStart->format('Y-m'),
            'monthLabel' => sprintf('%s %s', ucfirst(self::MONTH_NAMES[$monthNumber] ?? $monthStart->format('F')), $monthStart->format('Y')),
            'summary' => $summary,
            'emptyMessage' => $emptyMessage,
            'itemsByDate' => $itemsByDate,
            'days' => $days,
        ];
    }

    /** @param array<int, RunLog> $logs @return array<string,float> */
    private function buildDailyLoads(array $logs): array
    {
        $dailyLoads = [];
        foreach ($logs as $log) {
            $date = $log->getDate();
            if ($date === '' || strtoupper((string) ($log->getRunType() ?? '')) === 'RACE') {
                continue;
            }
            $durationMin = $this->resolveDurationMinutes($log);
            if ($durationMin === null || $durationMin <= 0) {
                continue;
            }
            $factor = match (strtoupper(trim((string) ($log->getRunType() ?? '')))) {
                'EF', 'ENDURANCE' => 1.0,
                'RECUP', 'RECUPERATION' => 0.8,
                'TEMPO' => 1.4,
                'SEUIL' => 1.7,
                'VMA', 'INTERVAL', 'INTERVALLE', 'FRACTIONNE', 'FRACTIONNEE' => 2.0,
                'RACE' => 2.3,
                default => 1.2,
            };
            $load = round($durationMin * $factor, 1);
            if ($load > 0) {
                $dailyLoads[$date] = ($dailyLoads[$date] ?? 0.0) + $load;
            }
        }
        return $dailyLoads;
    }

    private function resolveDurationMinutes(RunLog $log): ?float
    {
        $durationSec = $this->durationToSeconds($log->getDuration());
        $durationMin = $durationSec !== null ? ($durationSec / 60.0) : null;
        $resolved = $durationMin;
        if ($resolved === null || $resolved <= 0) {
            $km = (float) ($log->getKm() ?? 0.0);
            $paceSec = $this->paceToSeconds($log->getAllure());
            if ($km > 0.0) {
                $resolved = $paceSec !== null ? ($km * $paceSec) / 60.0 : ($km * 6.0);
            } else {
                $resolved = null;
            }
        }
        return $resolved;
    }

    /** @param array<string,float> $dailyLoads @return array{acute:float,chronicTotal:float} */
    private function computeAcwrLoads(array $dailyLoads, \DateTimeImmutable $today): array
    {
        $acute = 0.0;
        $chronicTotal = 0.0;
        foreach ($dailyLoads as $date => $load) {
            $day = $this->parseDay($date);
            if ($day === null) {
                continue;
            }
            $daysAgo = (int) floor(($today->getTimestamp() - $day->getTimestamp()) / 86400);
            if ($daysAgo < 0 || $daysAgo > 27) {
                continue;
            }
            $chronicTotal += $load;
            if ($daysAgo <= 6) {
                $acute += $load;
            }
        }
        return ['acute' => $acute, 'chronicTotal' => $chronicTotal];
    }

    /** @return array{key:string,label:string,color:string,recommendation:string} */
    private function resolveTrainingLoadStatus(?float $ratio): array
    {
        $status = [
            'key' => 'initial',
            'label' => 'Initialisation',
            'color' => 'var(--accent2)',
            'recommendation' => 'Continue regulierement pour stabiliser ta charge de reference.',
        ];
        if ($ratio !== null && $ratio < 0.8) {
            $status = ['key' => 'under', 'label' => 'Sous-charge', 'color' => 'var(--z2)', 'recommendation' => 'Tu peux ajouter une seance facile ou un peu de volume progressif.'];
        } elseif ($ratio !== null && $ratio <= 1.3) {
            $status = ['key' => 'balanced', 'label' => 'Equilibre', 'color' => self::COLOR_Z1, 'recommendation' => 'Charge bien equilibree: garde le cap et privilegie la regularite.'];
        } elseif ($ratio !== null && $ratio <= 1.5) {
            $status = ['key' => 'watch', 'label' => 'Vigilance', 'color' => 'var(--z3)', 'recommendation' => 'Legere hausse de charge: conserve une seance facile de recuperation.'];
        } elseif ($ratio !== null) {
            $status = ['key' => 'high', 'label' => 'Surcharge', 'color' => self::COLOR_ACCENT3, 'recommendation' => 'Hausse trop rapide: allege 24-48h et evite une grosse seance intense.'];
        }
        return $status;
    }

    /** @param array<string,float> $dailyLoads @return array<int,array{label:string,load:float}> */
    private function buildWeeklyLoadTrend(array $dailyLoads, \DateTimeImmutable $today): array
    {
        $monday = $today->modify('monday this week');
        $weekly = [];
        for ($offset = 7; $offset >= 0; $offset--) {
            $start = $monday->modify(sprintf('-%d week', $offset));
            $end = $start->modify('+6 day');
            $sum = 0.0;
            foreach ($dailyLoads as $date => $load) {
                $day = $this->parseDay($date);
                if ($day !== null && $day >= $start && $day <= $end) {
                    $sum += $load;
                }
            }
            $weekly[] = ['label' => $start->format('d/m'), 'load' => round($sum, 1)];
        }
        return $weekly;
    }

    /** @param array<int,array{ok:bool,title:string,msg:string}> $alerts @param array<int,RunLog> $logs */
    private function appendPaceProgressAlert(array &$alerts, array $logs): void
    {
        $nonRace = array_values(array_filter($logs, function (RunLog $log): bool {
            return $log->getDate() !== '' && $this->paceToSeconds($log->getAllure()) !== null && strtoupper((string) ($log->getRunType() ?? '')) !== 'RACE';
        }));
        usort($nonRace, static fn (RunLog $a, RunLog $b) => strcmp($a->getDate(), $b->getDate()));
        if (count($nonRace) < 4) {
            return;
        }
        $half = intdiv(count($nonRace), 2);
        $first = array_slice($nonRace, 0, $half);
        $last = array_slice($nonRace, $half);
        $firstAvg = array_sum(array_map(fn (RunLog $r): int => $this->paceToSeconds($r->getAllure()) ?? 0, $first)) / max(1, count($first));
        $lastAvg = array_sum(array_map(fn (RunLog $r): int => $this->paceToSeconds($r->getAllure()) ?? 0, $last)) / max(1, count($last));
        $delta = (int) round($firstAvg - $lastAvg);
        if ($delta > 15) {
            $alerts[] = ['ok' => true, 'title' => self::TITLE_PACE_PROGRESSION, 'msg' => sprintf('Amelioration moyenne de %s/km entre les premieres et dernieres sorties.', substr($this->secondsToDuration($delta), 3))];
        } elseif ($delta < -15) {
            $alerts[] = ['ok' => false, 'title' => self::TITLE_PACE_PROGRESSION, 'msg' => sprintf('Allure moyenne en baisse de %s/km sur les dernieres sorties.', substr($this->secondsToDuration(-$delta), 3))];
        } else {
            $alerts[] = ['ok' => true, 'title' => self::TITLE_PACE_PROGRESSION, 'msg' => 'Allure globalement stable sur la periode recente.'];
        }
    }

    /** @param array<int,array{ok:bool,title:string,msg:string}> $alerts @param array<int,RunLog> $logs */
    private function appendEfBpmAlert(array &$alerts, array $logs): void
    {
        $efBpms = [];
        foreach ($logs as $log) {
            $type = strtoupper((string) ($log->getRunType() ?? ''));
            if ($type === 'EF' && $log->getBpm() !== null) {
                $efBpms[] = (int) $log->getBpm();
            }
        }
        if (count($efBpms) >= 2) {
            $alerts[] = ['ok' => true, 'title' => 'BPM endurance fondamentale', 'msg' => sprintf('Plage observee: %d-%d bpm sur %d sortie(s) EF.', min($efBpms), max($efBpms), count($efBpms))];
        }
    }

    /** @param array<int,array{ok:bool,title:string,msg:string}> $alerts @param array<int,RunLog> $logs */
    private function appendTrainingGapAlert(array &$alerts, array $logs): void
    {
        $dated = array_values(array_filter($logs, static fn (RunLog $log): bool => $log->getDate() !== ''));
        usort($dated, static fn (RunLog $a, RunLog $b) => strcmp($a->getDate(), $b->getDate()));
        $maxGap = 0;
        for ($i = 1; $i < count($dated); $i++) {
            $d1 = $this->parseDay($dated[$i - 1]->getDate());
            $d2 = $this->parseDay($dated[$i]->getDate());
            if ($d1 === null || $d2 === null) {
                continue;
            }
            $gap = (int) round(($d2->getTimestamp() - $d1->getTimestamp()) / 86400);
            if ($gap > $maxGap) {
                $maxGap = $gap;
            }
        }
        if ($maxGap >= 10) {
            $alerts[] = ['ok' => false, 'title' => 'Coupure d\'entrainement', 'msg' => sprintf('Plus longue coupure detectee: %d jours entre deux sorties.', $maxGap)];
        }
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

    private function parseDay(string $date): ?\DateTimeImmutable
    {
        try {
            return (new \DateTimeImmutable($date))->setTime(0, 0, 0);
        } catch (\Throwable) {
            return null;
        }
    }
}
