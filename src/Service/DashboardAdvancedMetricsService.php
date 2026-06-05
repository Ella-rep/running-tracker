<?php

namespace App\Service;

use App\Entity\Plan;
use App\Entity\PlanProgress;
use App\Entity\RunLog;
use App\Entity\User;
use App\Repository\PlanDetailsRepository;
use App\Repository\PlanProgressRepository;
use App\Repository\PlanRepository;
use App\Repository\RunLogRepository;

/**
 * Handles dashboard advanced computations: plan widgets, training load and coherence alerts.
 */
final class DashboardAdvancedMetricsService
{
    private const TRAINING_GAP_LOOKBACK_DAYS = 60;
    private const COLOR_TEXT_MUTED = 'var(--text-muted)';
    private const COLOR_ACCENT2 = 'var(--accent2)';
    private const COLOR_ACCENT3 = 'var(--accent3)';
    private const COLOR_Z1 = 'var(--z1)';
    private const COLOR_Z2 = 'var(--z2)';
    private const COLOR_Z3 = 'var(--z3)';
    private const COLOR_Z4 = 'var(--z4)';
    private const TITLE_PACE_PROGRESSION = 'Progression allure';

    /**
     * Static config for each training load status.
     * Thresholds are applied in resolveStatusKey(); only display data lives here.
     *
     * @var array<string,array{label:string,color:string,recommendation:string}>
     */
    private const STATUS_CONFIG = [
        'initial' => [
            'label'          => 'Initialisation',
            'color'          => self::COLOR_ACCENT2,
            'recommendation' => 'Continue régulièrement pour stabiliser ta charge de référence.',
        ],
        'under' => [
            'label'          => 'Sous-charge',
            'color'          => self::COLOR_Z2,
            'recommendation' => 'Tu peux ajouter une séance facile ou un peu de volume progressif.',
        ],
        'under_watch' => [
            'label'          => 'Sous-charge légère',
            'color'          => self::COLOR_Z4,
            'recommendation' => 'Tu es juste en dessous de ta base: une sortie facile supplémentaire peut suffire.',
        ],
        'balanced' => [
            'label'          => 'Équilibre',
            'color'          => self::COLOR_Z1,
            'recommendation' => 'Charge bien équilibrée: garde le cap et privilégie la régularité.',
        ],
        'watch' => [
            'label'          => 'Vigilance',
            'color'          => self::COLOR_Z3,
            'recommendation' => 'Légère hausse de charge: allège un peu et garde une séance très facile.',
        ],
        'high' => [
            'label'          => 'Surcharge',
            'color'          => self::COLOR_ACCENT3,
            'recommendation' => 'Charge trop élevée: fais 24-48h de récupération et reporte l\'intensité.',
        ],
    ];

    private const MONTH_NAMES = [
        1 => 'janvier',
        2 => 'fevrier',
        3 => 'mars',
        4 => 'avril',
        5 => 'mai',
        6 => 'juin',
        7 => 'juillet',
        8 => 'aout',
        9 => 'septembre',
        10 => 'octobre',
        11 => 'novembre',
        12 => 'decembre',
    ];

    public function __construct(
        private RunLogRepository $runLogs,
        private PlanRepository $plans,
        private PlanDetailsRepository $planDetails,
        private PlanProgressRepository $planProgress,
    ) {}

    /**
     * @return array{progress:array<string,mixed>,calendar:array<string,mixed>}
     */
    public function buildPlanWidgets(User $user): array
    {
        $today = (new \DateTimeImmutable('today'))->setTime(0, 0, 0);
        $plans = $this->plans->findBy(['user' => $user], ['id' => 'ASC']);
        $planRows = $this->planDetails->findBy(['user' => $user], ['position' => 'ASC']);
        $rowsByPlanId = $this->groupRowsByPlanId($planRows);
        $loggedDetailIds = $this->runLogs->findLoggedDetailIds($user);
        $doneByProgressByPlan = $this->loadDoneSessionIndexesByPlan($user);
        $planSummaries = $this->buildPlanSummaries($plans, $rowsByPlanId, $loggedDetailIds, $doneByProgressByPlan);
        $publicPlanSummaries = array_map(static function (array $summary): array {
            unset($summary['plan']);
            return $summary;
        }, $planSummaries);
        $selection = $this->selectTargetPlan($planSummaries);
        $targetPlan = $selection['targetPlan'];
        $focusSummary = $selection['summary'];

        if (!$targetPlan instanceof Plan) {
            $monthStart = $today->modify('first day of this month')->setTime(0, 0, 0);
            $hasPlans = $publicPlanSummaries !== [];

            return [
                'progress' => [
                    'title' => 'Suivi des plans',
                    'focusTitle' => '',
                    'done' => 0,
                    'total' => 0,
                    'pct' => 0,
                    'plans' => $publicPlanSummaries,
                ],
                'calendar' => $this->buildPlanCalendar(
                    $monthStart,
                    $today,
                    [],
                    $hasPlans ? 'Aucun plan suivi' : 'Aucune séance programmée ce mois-ci',
                    $hasPlans
                        ? 'Ajoute un plan au suivi pour afficher son calendrier.'
                        : 'Ajoute un plan avec des dates de séances pour remplir ce calendrier.'
                ),
            ];
        }

        $targetPlanId = $targetPlan->getId();
        $rows = is_int($targetPlanId) ? ($rowsByPlanId[$targetPlanId] ?? []) : [];
        $doneByProgress = is_int($targetPlanId) ? ($doneByProgressByPlan[(string) $targetPlanId] ?? []) : [];
        $aggregates = $this->aggregatePlanRows($rows, $loggedDetailIds, $doneByProgress);

        $datedRows = $aggregates['datedRows'];
        $itemsByDate = $aggregates['itemsByDate'];

        usort($datedRows, static fn(\DateTimeImmutable $left, \DateTimeImmutable $right): int => $left <=> $right);

        $monthStart = $this->resolveVisibleMonthStart($today, $datedRows);
        $monthKey = $monthStart->format('Y-m');
        $visibleDates = array_filter($datedRows, static fn(\DateTimeImmutable $sessionDate): bool => $sessionDate->format('Y-m') === $monthKey);
        // Calendar summary is based only on the currently visible month.
        $visibleCount = count($visibleDates);
        $summary = 'Aucune séance programmée ce mois-ci';
        if ($visibleCount > 0) {
            $pluralSuffix = $visibleCount > 1 ? 's' : '';
            $summary = sprintf('%d séance%s programmée%s', $visibleCount, $pluralSuffix, $pluralSuffix);
        }

        $focusTitle = is_array($focusSummary) ? (string) ($focusSummary['title'] ?? '') : '';
        $focusDone = is_array($focusSummary) ? (int) ($focusSummary['done'] ?? 0) : 0;
        $focusTotal = is_array($focusSummary) ? (int) ($focusSummary['total'] ?? 0) : 0;
        $focusPct = is_array($focusSummary) ? (int) ($focusSummary['pct'] ?? 0) : 0;

        return [
            'progress' => [
                'title' => 'Suivi des plans',
                'focusTitle' => $focusTitle,
                'done' => $focusDone,
                'total' => $focusTotal,
                'pct' => $focusPct,
                'plans' => $publicPlanSummaries,
            ],
            'calendar' => $this->buildPlanCalendar(
                $monthStart,
                $today,
                $itemsByDate,
                $summary,
                $datedRows === [] ? 'Ajoute des dates à ton plan pour voir les séances sur le calendrier.' : ''
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
                'statusLabel' => 'Pas de données',
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

        // Chronic reference is the 28-day total normalized to one week.
        $chronic = $chronicTotal / 4.0;
        // Ratio compares last 7 days against the normalized 28-day baseline.
        // chronicTotal > acute means at least some sessions predate the 7-day window,
        // which is required for a meaningful chronic baseline. Without it (e.g. first run),
        // ratio would always be 4.0 (acute / (acute/4)), producing a false "Surcharge" alert.
        $ratio = ($chronic > 0 && $chronicTotal > $acute) ? round($acute / $chronic, 2) : null;
        // Delta is the relative gap between acute and chronic (%).
        $deltaPct = $chronic > 0 ? (int) round((($acute - $chronic) / $chronic) * 100) : 0;
        $status = $this->resolveTrainingLoadStatus($ratio, $logs);
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
     * @return array<int,array{ok:bool,title:string,msg:string,details?:array<int,string>}>
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

    /**
     * @param array<int, array{id:int,title:string,done:int,total:int,pct:int,isExample:bool,tracked:bool,plan:Plan}> $summaries
     * @return array{targetPlan:?Plan,summary:?array{id:int,title:string,done:int,total:int,pct:int,isExample:bool,tracked:bool,plan:Plan}}
     */
    private function selectTargetPlan(array $summaries): array
    {
        $trackedSummaries = array_values(array_filter($summaries, static fn(array $summary): bool => (bool) ($summary['tracked'] ?? false)));

        return [
            'targetPlan' => isset($trackedSummaries[0]) ? $trackedSummaries[0]['plan'] : null,
            'summary' => $trackedSummaries[0] ?? null,
        ];
    }

    /**
     * @param array<int, Plan> $plans
     * @param array<int, array<int, mixed>> $rowsByPlanId
     * @param array<int, mixed> $loggedDetailIds
     * @param array<string, array<int,bool>> $doneByProgressByPlan
     * @return array<int, array{id:int,title:string,done:int,total:int,pct:int,isExample:bool,tracked:bool,plan:Plan}>
     */
    private function buildPlanSummaries(array $plans, array $rowsByPlanId, array $loggedDetailIds, array $doneByProgressByPlan): array
    {
        $summaries = [];

        foreach ($plans as $plan) {
            $planId = $plan->getId();
            if (!is_int($planId)) {
                continue;
            }

            $name = trim((string) $plan->getName());
            $isExample = $name === 'starter';
            if ($isExample) {
                continue;
            }

            $rows = $rowsByPlanId[$planId] ?? [];
            $aggregates = $this->aggregatePlanRows($rows, $loggedDetailIds, $doneByProgressByPlan[(string) $planId] ?? []);
            $total = count($rows);
            $done = $aggregates['done'];

            $summaries[] = [
                'id' => $planId,
                'title' => $name,
                'done' => $done,
                'total' => $total,
                // Completion percent is rounded to an integer for the dashboard card.
                'pct' => $total > 0 ? (int) round(($done / $total) * 100) : 0,
                'isExample' => $isExample,
                'tracked' => $plan->isDashboardTracked(),
                'plan' => $plan,
            ];
        }

        usort($summaries, static function (array $left, array $right): int {
            foreach (['pct', 'done', 'total', 'id'] as $field) {
                $comparison = (int) $right[$field] <=> (int) $left[$field];
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return 0;
        });

        return $summaries;
    }

    /**
     * @param array<int, mixed> $rows
     * @return array<int, array<int, mixed>>
     */
    private function groupRowsByPlanId(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $planId = $row->getPlan()->getId();
            if (!is_int($planId)) {
                continue;
            }

            $grouped[$planId] ??= [];
            $grouped[$planId][] = $row;
        }

        return $grouped;
    }

    /**
     * @param array<int,mixed> $rows
     * @param array<int,mixed> $loggedDetailIds
     * @param array<int,bool> $doneByProgress
     * @return array{done:int,datedRows:array<int,\DateTimeImmutable>,itemsByDate:array<string,array<int,array<string,mixed>>>}
     */
    private function aggregatePlanRows(array $rows, array $loggedDetailIds, array $doneByProgress): array
    {
        return array_reduce($rows, static function (array $carry, $row) use ($loggedDetailIds, $doneByProgress): array {
            $rowId = $row->getId();
            $sessionIndex = max(0, $row->getPosition() - 1);
            // A session is considered done if marked done, logged, or flagged done in progress tracking.
            $isDone = $row->isDone()
                || ($rowId !== null && isset($loggedDetailIds[$rowId]))
                || isset($doneByProgress[$sessionIndex]);

            $carry['done'] += $isDone ? 1 : 0;
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
                'detailId' => $rowId,
                'planId' => $row->getPlan()->getId(),
                'sessionType' => $row->getSessionType(),
                'label' => sprintf('Séance %d', $row->getPosition()),
                'format' => $row->getFormat(),
                'pe' => $row->getPe(),
                'isDone' => $isDone,
                'hasLog' => $rowId !== null && isset($loggedDetailIds[$rowId]),
                'isOptional' => $row->isOptional(),
            ];
            return $carry;
        }, ['done' => 0, 'datedRows' => [], 'itemsByDate' => []]);
    }

    /** @return array<string, array<int,bool>> */
    private function loadDoneSessionIndexesByPlan(User $user): array
    {
        $rows = $this->planProgress->findBy(['user' => $user, 'done' => true]);

        $doneByProgress = [];
        foreach ($rows as $row) {
            if (!$row instanceof PlanProgress) {
                continue;
            }

            $planKey = trim($row->getPlanKey());
            if ($planKey === '') {
                continue;
            }

            $doneByProgress[$planKey] ??= [];
            $doneByProgress[$planKey][$row->getSessionIndex()] = true;
        }

        return $doneByProgress;
    }

    /** @param array<int,\DateTimeImmutable> $datedRows */
    private function resolveVisibleMonthStart(\DateTimeImmutable $today, array $datedRows): \DateTimeImmutable
    {
        $monthStart = $today->modify('first day of this month')->setTime(0, 0, 0);
        $currentMonthKey = $monthStart->format('Y-m');
        $datedMonthKeys = array_values(array_unique(array_map(static fn(\DateTimeImmutable $sessionDate): string => $sessionDate->format('Y-m'), $datedRows)));
        $futureDates = array_values(array_filter($datedRows, static fn(\DateTimeImmutable $sessionDate): bool => $sessionDate >= $today));
        $visibleMonthKey = $currentMonthKey;

        // Prefer current month; if empty, jump to the next future month with sessions,
        // otherwise fall back to the first month that has any dated session.
        if (!in_array($currentMonthKey, $datedMonthKeys, true)) {
            if (!empty($futureDates)) {
                $visibleMonthKey = $futureDates[0]->format('Y-m');
            } elseif (!empty($datedMonthKeys)) {
                $visibleMonthKey = $datedMonthKeys[0];
            } else {
                $visibleMonthKey = $currentMonthKey;
            }
        }
        return $visibleMonthKey === $currentMonthKey ? $monthStart : new \DateTimeImmutable($visibleMonthKey . '-01');
    }

    /** @param array<string,array<int,array<string,mixed>>> $itemsByDate @return array<string,mixed> */
    private function buildPlanCalendar(\DateTimeImmutable $monthStart, \DateTimeImmutable $today, array $itemsByDate, string $summary, string $emptyMessage): array
    {
        // Build a fixed 6x7 grid (42 cells) starting on Monday for stable calendar layout.
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
            'title' => 'Calendrier des séances prévues',
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
            // Weighted load = duration (minutes) x intensity factor by run type.
            $factor = match (strtoupper(trim((string) ($log->getRunType() ?? '')))) {
                'EF', 'ENDURANCE' => 1.0,
                'RECUP', 'RECUPERATION' => 0.8,
                'SL' => 1.2,
                'T', 'TEMPO' => 1.4,
                'FL', 'SEUIL' => 1.7,
                'FC', 'VMA', 'INTERVAL', 'INTERVALLE', 'FRACTIONNE', 'FRACTIONNEE' => 2.0,
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
        // Fallback when duration is missing:
        // 1) estimate from km * pace if pace exists
        // 2) otherwise use a simple default of 6 min/km.
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

    /**
     * Computes ACWR-style rolling loads on a sliding window (not calendar months).
     *
     * Detail of the calculation:
     * - Keep only sessions from D-27 to D (28 days total).
     * - Acute load (7 days) = sum of loads from D-6 to D.
     * - Chronic reference = sum of loads from D-27 to D, then divided by 4.
     * - Ratio shown in UI = acute / chronic.
     * - Status thresholds:
     *   < 0.80 = Sous-charge, 0.80-0.89 = Sous-charge légère,
     *   0.90-1.30 = Équilibre, 1.31-1.50 = Vigilance, > 1.50 = Surcharge.
     *
     * Example:
     * - If 7-day load is 131.4 and 28-day total is 713.2,
     *   chronic = 713.2 / 4 = 178.3 and ratio = 131.4 / 178.3 = 0.74.
     *
     * @param array<string,float> $dailyLoads
     * @return array{acute:float,chronicTotal:float}
     */
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

    /**
     * @param array<int,RunLog> $logs
     * @return array{key:string,label:string,color:string,recommendation:string}
     */
    private function resolveTrainingLoadStatus(?float $ratio, array $logs): array
    {
        $key = $this->resolveStatusKey($ratio);
        $config = self::STATUS_CONFIG[$key];
        $status = ['key' => $key, ...$config];

        if (in_array($status['key'], ['under', 'under_watch'], true)) {
            $dated = array_values(array_filter($logs, static fn(RunLog $log): bool => trim($log->getDate()) !== ''));
            usort($dated, static fn(RunLog $a, RunLog $b): int => strcmp($b->getDate(), $a->getDate()));
            $recent = array_slice($dated, 0, 3);

            if (count($recent) === 3) {
                $difficultCount = 0;
                $heatHintCount = 0;

                foreach ($recent as $log) {
                    $effort = strtolower(trim((string) ($log->getPerceivedEffort() ?? '')));
                    if ($effort === 'difficile') {
                        $difficultCount++;
                    }

                    $notes = strtolower(trim((string) ($log->getNotes() ?? '')));
                    if ($notes !== '') {
                        foreach (['chaleur', 'chaud', 'canicule', 'soleil', 'hot', 'heat', 'temperature'] as $token) {
                            if (str_contains($notes, $token)) {
                                $heatHintCount++;
                                break;
                            }
                        }
                    }
                }

                if ($difficultCount === 3) {
                    $status['recommendation'] = $heatHintCount >= 2
                        ? 'Ratio bas, mais tes 3 dernières sorties ont été tagguées difficiles et semblent liées à la chaleur. Garde une semaine légère, cours plutôt tôt/tard et privilégie hydratation et récupération.'
                        : 'Ratio bas, mais tes 3 dernières sorties ont été tagguées difficiles. Mieux vaut consolider la récupération avant d\'ajouter du volume.';
                }
            }
        }

        return $status;
    }

    /**
     * Maps an ACWR ratio to a STATUS_CONFIG key.
     * Thresholds are ordered from most severe under-load to most severe over-load.
     * A null ratio means no chronic baseline yet — return 'initial'.
     */
    private function resolveStatusKey(?float $ratio): string
    {
        switch ($ratio) {
            case null:
                $status = 'initial';
                break;
            case $ratio < 0.8:
                $status = 'under';
                break;
            case $ratio < 0.9:
                $status = 'under_watch';
                break;
            case $ratio <= 1.3:
                $status = 'balanced';
                break;
            case $ratio <= 1.5:
                $status = 'watch';
                break;
            default:
                $status = 'high';
                break;
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
            // Sum each Monday-Sunday bucket to feed the trend chart.
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

    /** @param array<int,array{ok:bool,title:string,msg:string,details?:array<int,string>}> $alerts @param array<int,RunLog> $logs */
    private function appendPaceProgressAlert(array &$alerts, array $logs): void
    {
        $nonRace = array_values(array_filter($logs, function (RunLog $log): bool {
            return $log->getDate() !== '' && $this->paceToSeconds($log->getAllure()) !== null && strtoupper((string) ($log->getRunType() ?? '')) !== 'RACE';
        }));
        usort($nonRace, static fn(RunLog $a, RunLog $b) => strcmp($a->getDate(), $b->getDate()));
        if (count($nonRace) < 4) {
            return;
        }

        // Group by normalized type so comparisons are EF vs EF, SL vs SL, etc.
        $byType = [];
        foreach ($nonRace as $log) {
            $type = $this->normalizeRunType((string) ($log->getRunType() ?? ''));
            $byType[$type][] = $log;
        }
        $typesWithData = array_filter($byType, static fn(array $group): bool => count($group) >= 4);

        // No single type has enough samples — fall back to unfiltered comparison.
        if (empty($typesWithData)) {
            $this->appendMixedPaceAlert($alerts, $nonRace);
            return;
        }

        $details = [];
        $improvements = 0;
        $declines = 0;

        foreach ($typesWithData as $type => $group) {
            $half = intdiv(count($group), 2);
            $first = array_slice($group, 0, $half);
            $last = array_slice($group, $half);
            $firstAvg = array_sum(array_map(fn(RunLog $r): int => $this->paceToSeconds($r->getAllure()) ?? 0, $first)) / max(1, count($first));
            $lastAvg = array_sum(array_map(fn(RunLog $r): int => $this->paceToSeconds($r->getAllure()) ?? 0, $last)) / max(1, count($last));
            $delta = (int) round($firstAvg - $lastAvg);
            $firstStart = $this->parseDay($first[0]->getDate());
            $lastEnd = $this->parseDay($last[count($last) - 1]->getDate());
            $periodStr = ($firstStart instanceof \DateTimeImmutable && $lastEnd instanceof \DateTimeImmutable)
                ? sprintf(', %s au %s', $firstStart->format('d/m/Y'), $lastEnd->format('d/m/Y'))
                : '';
            $avgFirstStr = substr($this->secondsToDuration((int) round($firstAvg)), 3);
            $avgLastStr = substr($this->secondsToDuration((int) round($lastAvg)), 3);

            // 15s/km guard band to avoid noisy flips.
            if ($delta > 15) {
                $improvements++;
                $details[] = sprintf('%s (%d sorties%s): amelioration de +%s/km. Debut: %s/km → Fin: %s/km.',
                    $type, count($group), $periodStr, substr($this->secondsToDuration($delta), 3), $avgFirstStr, $avgLastStr);
            } elseif ($delta < -15) {
                $declines++;
                $details[] = sprintf('%s (%d sorties%s): baisse de %s/km. Debut: %s/km → Fin: %s/km.',
                    $type, count($group), $periodStr, substr($this->secondsToDuration(-$delta), 3), $avgFirstStr, $avgLastStr);
            } else {
                $details[] = sprintf('%s (%d sorties%s): allure stable. Debut: %s/km → Fin: %s/km.',
                    $type, count($group), $periodStr, $avgFirstStr, $avgLastStr);
            }
        }

        $typeCount = count($typesWithData);
        $ok = $improvements >= $declines;
        if ($improvements > $declines) {
            $msg = sprintf('Progression detectee sur %d type(s) d\'entrainement (base: %d sorties).', $typeCount, count($nonRace));
        } elseif ($declines > $improvements) {
            $msg = sprintf('Baisse d\'allure sur %d type(s) d\'entrainement (base: %d sorties).', $typeCount, count($nonRace));
        } else {
            $msg = sprintf('Allure stable sur %d type(s) d\'entrainement (base: %d sorties).', $typeCount, count($nonRace));
        }

        $alerts[] = ['ok' => $ok, 'title' => self::TITLE_PACE_PROGRESSION, 'msg' => $msg, 'details' => $details];
    }

    /** @param array<int,array{ok:bool,title:string,msg:string,details?:array<int,string>}> $alerts @param array<int,RunLog> $logs */
    private function appendMixedPaceAlert(array &$alerts, array $logs): void
    {
        $half = intdiv(count($logs), 2);
        $first = array_slice($logs, 0, $half);
        $last = array_slice($logs, $half);
        $firstAvg = array_sum(array_map(fn(RunLog $r): int => $this->paceToSeconds($r->getAllure()) ?? 0, $first)) / max(1, count($first));
        $lastAvg = array_sum(array_map(fn(RunLog $r): int => $this->paceToSeconds($r->getAllure()) ?? 0, $last)) / max(1, count($last));
        $delta = (int) round($firstAvg - $lastAvg);
        $firstStart = $this->parseDay($first[0]->getDate());
        $firstEnd = $this->parseDay($first[count($first) - 1]->getDate());
        $lastStart = $this->parseDay($last[0]->getDate());
        $lastEnd = $this->parseDay($last[count($last) - 1]->getDate());
        $details = [];
        if ($firstStart instanceof \DateTimeImmutable && $firstEnd instanceof \DateTimeImmutable) {
            $details[] = sprintf('Premiere periode comparee: %s au %s (%d sortie(s)).', $firstStart->format('d/m/Y'), $firstEnd->format('d/m/Y'), count($first));
        }
        if ($lastStart instanceof \DateTimeImmutable && $lastEnd instanceof \DateTimeImmutable) {
            $details[] = sprintf('Derniere periode comparee: %s au %s (%d sortie(s)).', $lastStart->format('d/m/Y'), $lastEnd->format('d/m/Y'), count($last));
        }
        $details[] = sprintf('Allure moyenne debut de periode: %s/km.', substr($this->secondsToDuration((int) round($firstAvg)), 3));
        $details[] = sprintf('Allure moyenne fin de periode: %s/km.', substr($this->secondsToDuration((int) round($lastAvg)), 3));
        // 15s/km guard band to avoid noisy "improved/degraded" flips.
        if ($delta > 15) {
            $alerts[] = ['ok' => true, 'title' => self::TITLE_PACE_PROGRESSION, 'msg' => sprintf('Amelioration moyenne de %s/km entre les premieres et dernieres sorties (base: %d sorties).', substr($this->secondsToDuration($delta), 3), count($logs)), 'details' => $details];
        } elseif ($delta < -15) {
            $alerts[] = ['ok' => false, 'title' => self::TITLE_PACE_PROGRESSION, 'msg' => sprintf('Allure moyenne en baisse de %s/km sur les dernieres sorties (base: %d sorties).', substr($this->secondsToDuration(-$delta), 3), count($logs)), 'details' => $details];
        } else {
            $alerts[] = ['ok' => true, 'title' => self::TITLE_PACE_PROGRESSION, 'msg' => sprintf('Allure globalement stable sur la periode recente (base: %d sorties).', count($logs)), 'details' => $details];
        }
    }

    private function normalizeRunType(string $type): string
    {
        return match(strtoupper(trim($type))) {
            'EF', 'ENDURANCE'                                                    => 'EF',
            'RECUP', 'RECUPERATION'                                              => 'RECUP',
            'SL'                                                                 => 'SL',
            'T', 'TEMPO'                                                         => 'T',
            'FL', 'SEUIL'                                                        => 'FL',
            'FC', 'VMA', 'INTERVAL', 'INTERVALLE', 'FRACTIONNE', 'FRACTIONNEE'  => 'FC',
            default => strtoupper(trim($type)) ?: 'AUTRE',
        };
    }

    /** @param array<int,array{ok:bool,title:string,msg:string,details?:array<int,string>}> $alerts @param array<int,RunLog> $logs */
    private function appendEfBpmAlert(array &$alerts, array $logs): void
    {
        $efEntries = [];
        foreach ($logs as $log) {
            $type = strtoupper((string) ($log->getRunType() ?? ''));
            if ($type === 'EF' && $log->getBpm() !== null) {
                $efEntries[] = [
                    'date' => $log->getDate(),
                    'bpm' => (int) $log->getBpm(),
                    'km' => (float) ($log->getKm() ?? 0.0),
                ];
            }
        }
        if (count($efEntries) >= 2) {
            usort($efEntries, static fn(array $left, array $right): int => strcmp((string) $left['date'], (string) $right['date']));
            $efBpms = array_map(static fn(array $entry): int => (int) $entry['bpm'], $efEntries);
            // Keep latest samples in details while global min/max/avg use full EF set.
            $recentEntries = array_slice($efEntries, -3);
            $details = [
                sprintf('BPM moyen observe: %d bpm.', (int) round(array_sum($efBpms) / count($efBpms))),
                sprintf('BPM min/max observes: %d-%d bpm.', min($efBpms), max($efBpms)),
            ];

            foreach ($recentEntries as $entry) {
                $date = $this->parseDay((string) $entry['date']);
                $details[] = sprintf(
                    'EF du %s: %d bpm%s',
                    $date instanceof \DateTimeImmutable ? $date->format('d/m/Y') : (string) $entry['date'],
                    (int) $entry['bpm'],
                    (float) $entry['km'] > 0 ? sprintf(' · %.1f km', (float) $entry['km']) : ''
                );
            }

            $alerts[] = ['ok' => true, 'title' => 'BPM endurance fondamentale', 'msg' => sprintf('Plage observee: %d-%d bpm sur %d sortie(s) EF.', min($efBpms), max($efBpms), count($efBpms)), 'details' => $details];
        }
    }

    /** @param array<int,array{ok:bool,title:string,msg:string,details?:array<int,string>}> $alerts @param array<int,RunLog> $logs */
    private function appendTrainingGapAlert(array &$alerts, array $logs): void
    {
        $today = (new \DateTimeImmutable('today'))->setTime(0, 0, 0);
        // Look only at recent history to avoid very old gaps skewing the warning.
        $windowStart = $today->modify(sprintf('-%d days', self::TRAINING_GAP_LOOKBACK_DAYS));

        $dated = array_values(array_filter($logs, function (RunLog $log) use ($windowStart): bool {
            if ($log->getDate() === '') {
                return false;
            }

            $day = $this->parseDay($log->getDate());
            return $day instanceof \DateTimeImmutable && $day >= $windowStart;
        }));
        usort($dated, static fn(RunLog $a, RunLog $b) => strcmp($a->getDate(), $b->getDate()));
        if (count($dated) < 2) {
            return;
        }

        $maxGap = 0;
        $maxGapStart = null;
        $maxGapEnd = null;
        // Compute maximum calendar-day gap between consecutive runs.
        for ($i = 1; $i < count($dated); $i++) {
            $d1 = $this->parseDay($dated[$i - 1]->getDate());
            $d2 = $this->parseDay($dated[$i]->getDate());
            if ($d1 === null || $d2 === null) {
                continue;
            }
            $gap = (int) round(($d2->getTimestamp() - $d1->getTimestamp()) / 86400);
            if ($gap > $maxGap) {
                $maxGap = $gap;
                $maxGapStart = $dated[$i - 1];
                $maxGapEnd = $dated[$i];
            }
        }
        if ($maxGap >= 10) {
            $details = [];
            if ($maxGapStart instanceof RunLog && $maxGapEnd instanceof RunLog) {
                $startDate = $this->parseDay($maxGapStart->getDate());
                $endDate = $this->parseDay($maxGapEnd->getDate());
                if ($startDate instanceof \DateTimeImmutable && $endDate instanceof \DateTimeImmutable) {
                    $details[] = sprintf('Derniere sortie avant coupure: %s', $startDate->format('d/m/Y'));
                    $details[] = sprintf('Reprise détectée: %s', $endDate->format('d/m/Y'));
                    $details[] = sprintf('Intervalle calcule: %d jours calendaires entre ces deux sorties.', $maxGap);
                }

                $startType = trim((string) ($maxGapStart->getRunType() ?? ''));
                $endType = trim((string) ($maxGapEnd->getRunType() ?? ''));
                $startKm = (float) ($maxGapStart->getKm() ?? 0.0);
                $endKm = (float) ($maxGapEnd->getKm() ?? 0.0);
                $details[] = sprintf(
                    'Sortie precedente: %s%s',
                    $startType !== '' ? $startType : 'type non renseigne',
                    $startKm > 0 ? sprintf(' · %.1f km', $startKm) : ''
                );
                $details[] = sprintf(
                    'Sortie suivante: %s%s',
                    $endType !== '' ? $endType : 'type non renseigne',
                    $endKm > 0 ? sprintf(' · %.1f km', $endKm) : ''
                );
                $details[] = sprintf('Fenetre analysee: %d derniers jours (%d sortie(s) prises en compte).', self::TRAINING_GAP_LOOKBACK_DAYS, count($dated));
            }

            $alerts[] = [
                'ok' => false,
                'title' => 'Coupure d\'entrainement',
                'msg' => sprintf('Plus longue coupure détectée: %d jours entre deux sorties.', $maxGap),
                'details' => $details,
            ];
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
                    // mm:ss per km to total seconds per km.
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
        // Normalize seconds to hh:mm:ss for consistent display formatting.
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
