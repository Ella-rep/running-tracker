<?php

namespace App\Service;

use App\Entity\Race;
use App\Entity\RunLog;
use App\Entity\User;
use App\Entity\Plan;
use App\Repository\PlanDetailsRepository;
use App\Repository\PlanRepository;
use App\Repository\RaceRepository;
use App\Repository\RunLogRepository;

final class DashboardMetricsService
{
    public function __construct(
        private RunLogRepository $runLogs,
        private RaceRepository $races,
        private PlanRepository $plans,
        private PlanDetailsRepository $planDetails,
    ) {}

    /**
     * @return array{
     *   kpis: array{
     *     avgAllure: string,
     *     longestDuration: string,
     *     longestDistance: float,
     *     avgBpm: int|string
     *   },
    *   monthlyBars: array<int, array{label:string,km:float,height:int}>,
     *   projections: array<int, array{label:string,time:string,pace:string,color:string}>,
    *   projectionsMeta: string,
    *   trainingLoad: array{hasData:bool,statusKey:string,statusLabel:string,statusColor:string,acute:float,chronic:float,ratio:float|null,deltaPct:int,recommendation:string,weekly:array<int, array{label:string,load:float}>},
    *   efKpis: array{items: array<int, array{label:string,value:string,valueColor:string,meta:string}>, emptyMessage:string},
    *   ef: array{
    *     hasData: bool,
    *     emptyMessage: string,
    *     chart: array{
    *       paceTicks: array<int, array{t:float,label:string}>,
    *       bpmTicks: array<int, array{t:float,label:string}>,
    *       pacePoints: array<int, array{x:float,y:float}>,
    *       bpmPoints: array<int, array{x:float,y:float}>,
    *       efDots: array<int, array{x:float,paceY:float,bpmY:float}>
    *     },
    *     tableRows: array<int, array{date:string,km:string,bpm:string,allure:string,idx:string,idxColor:string,trendLabel:string,trendColor:string}>,
    *     meta: string
    *   },
    *   coherenceAlerts: array<int, array{ok:bool,title:string,msg:string}>,
    *   racesTable: array<int, array{statusClass:string,statusLabel:string,name:string,date:string,dist:string,obj:string,real:string}>,
    *   planProgress: array{title:string,done:int,total:int,pct:int},
    *   planCalendar: array{title:string,monthLabel:string,summary:string,emptyMessage:string,days:array<int, array{date:string,day:int,inMonth:bool,isToday:bool,items:array<int, array{label:string,format:string,pe:?string,isDone:bool,isOptional:bool}>}>}
     * }
     */
    public function build(User $user): array
    {
        /** @var array<int, RunLog> $logs */
        $logs = $this->runLogs->findBy(['user' => $user], ['date' => 'DESC'], 500);

        $avgAllure = $this->computeAverageAllure($logs);
        $longestDistance = $this->computeLongestDistance($logs);
        $longestDuration = $this->computeLongestDuration($logs);
        $avgBpm = $this->computeAverageBpm($logs);
        $monthlyBars = $this->buildMonthlyBars($logs);
        $efKpis = $this->buildEfKpis($logs);
        $ef = $this->buildEfSection($logs);
        $trainingLoad = $this->buildTrainingLoad($logs);
        $coherenceAlerts = $this->buildCoherenceAlerts($logs);
        $racesTable = $this->buildDashboardRacesTable($user);
        $planWidgets = $this->buildPlanWidgets($user);

        [$projections, $projectionsMeta] = $this->buildProjections($logs);

        return [
            'kpis' => [
                'avgAllure' => $avgAllure,
                'longestDuration' => $longestDuration,
                'longestDistance' => $longestDistance,
                'avgBpm' => $avgBpm,
            ],
            'monthlyBars' => $monthlyBars,
            'projections' => $projections,
            'projectionsMeta' => $projectionsMeta,
            'trainingLoad' => $trainingLoad,
            'efKpis' => $efKpis,
            'ef' => $ef,
            'coherenceAlerts' => $coherenceAlerts,
            'racesTable' => $racesTable,
            'planProgress' => $planWidgets['progress'],
            'planCalendar' => $planWidgets['calendar'],
        ];
    }

    /**
     * @return array{
     *   progress: array{title:string,done:int,total:int,pct:int},
    *   calendar: array{title:string,monthLabel:string,summary:string,emptyMessage:string,days:array<int, array{date:string,day:int,inMonth:bool,isToday:bool,items:array<int, array{kind:string,detailId:?int,planId:?int,label:string,format:string,pe:?string,isDone:bool,isOptional:bool}>}>}
     * }
     */
    private function buildPlanWidgets(User $user): array
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

        $targetPlan = $selection['latest'] ?? $selection['starter'];
        $isExample = $selection['latest'] === null
            && $selection['starter'] instanceof Plan;
        $today = (new \DateTimeImmutable('today'))->setTime(0, 0, 0);
        $monthNames = [
            1 => 'janvier', 2 => 'fevrier', 3 => 'mars', 4 => 'avril', 5 => 'mai', 6 => 'juin',
            7 => 'juillet', 8 => 'aout', 9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'decembre',
        ];
        $buildCalendar = static function (\DateTimeImmutable $monthStart, \DateTimeImmutable $today, array $itemsByDate, array $monthNames, string $summary, string $emptyMessage): array {
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
                'monthLabel' => sprintf('%s %s', ucfirst($monthNames[$monthNumber] ?? $monthStart->format('F')), $monthStart->format('Y')),
                'summary' => $summary,
                'emptyMessage' => $emptyMessage,
                'days' => $days,
            ];
        };

        if (!$targetPlan instanceof Plan) {
            $monthStart = $today->modify('first day of this month')->setTime(0, 0, 0);

            return [
                'progress' => [
                    'title' => 'Progression du plan exemple',
                    'done' => 0,
                    'total' => 0,
                    'pct' => 0,
                ],
                'calendar' => $buildCalendar(
                    $monthStart,
                    $today,
                    [],
                    $monthNames,
                    'Aucune seance programmee ce mois-ci',
                    'Ajoute un plan avec des dates de seances pour remplir ce calendrier.'
                ),
            ];
        }

        $rows = $this->planDetails->findBy(['user' => $user, 'plan' => $targetPlan], ['position' => 'ASC']);
        $total = count($rows);
        $aggregates = array_reduce($rows, static function (array $carry, $row): array {
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
                'label' => sprintf('Seance %d', $row->getPosition()),
                'format' => $row->getFormat(),
                'pe' => $row->getPe(),
                'isDone' => $row->isDone(),
                'isOptional' => $row->isOptional(),
            ];

            return $carry;
        }, ['done' => 0, 'datedRows' => [], 'itemsByDate' => []]);

        $done = $aggregates['done'];
        $datedRows = $aggregates['datedRows'];
        $itemsByDate = $aggregates['itemsByDate'];

        usort($datedRows, static fn (\DateTimeImmutable $left, \DateTimeImmutable $right): int => $left <=> $right);

        $monthStart = $today->modify('first day of this month')->setTime(0, 0, 0);
        $currentMonthKey = $monthStart->format('Y-m');
        $datedMonthKeys = array_values(array_unique(array_map(static fn (\DateTimeImmutable $sessionDate): string => $sessionDate->format('Y-m'), $datedRows)));
        $futureDates = array_values(array_filter($datedRows, static fn (\DateTimeImmutable $sessionDate): bool => $sessionDate >= $today));
        $visibleMonthKey = $currentMonthKey;

        if (!in_array($currentMonthKey, $datedMonthKeys, true)) {
            $visibleMonthKey = $futureDates[0]->format('Y-m') ?? ($datedMonthKeys[0] ?? $currentMonthKey);
        }
        if ($visibleMonthKey !== $currentMonthKey) {
            $monthStart = new \DateTimeImmutable($visibleMonthKey . '-01');
        }

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
            'calendar' => $buildCalendar(
                $monthStart,
                $today,
                $itemsByDate,
                $monthNames,
                $summary,
                $datedRows === [] ? 'Ajoute des dates a ton plan pour voir les seances sur le calendrier.' : ''
            ),
        ];
    }

    /**
     * @param array<int, RunLog> $logs
     * @return array{hasData:bool,statusKey:string,statusLabel:string,statusColor:string,acute:float,chronic:float,ratio:float|null,deltaPct:int,recommendation:string,weekly:array<int, array{label:string,load:float}>}
     */
    private function buildTrainingLoad(array $logs): array
    {
        $dailyLoads = [];

        $factorForRunType = static function (string $runType): float {
            $key = strtoupper(trim($runType));

            return match ($key) {
                'EF', 'ENDURANCE' => 1.0,
                'RECUP', 'RECUPERATION' => 0.8,
                'TEMPO' => 1.4,
                'SEUIL' => 1.7,
                'VMA', 'INTERVAL', 'INTERVALLE', 'FRACTIONNE', 'FRACTIONNEE' => 2.0,
                'RACE' => 2.3,
                default => 1.2,
            };
        };

        foreach ($logs as $log) {
            $date = $log->getDate();
            if ($date === '' || strtoupper((string) ($log->getRunType() ?? '')) === 'RACE') {
                continue;
            }

            $durationSec = $this->durationToSeconds($log->getDuration());
            $durationMin = $durationSec !== null ? ($durationSec / 60.0) : null;

            if ($durationMin === null || $durationMin <= 0) {
                $km = (float) ($log->getKm() ?? 0.0);
                $paceSec = $this->paceToSeconds($log->getAllure());

                if ($km > 0.0 && $paceSec !== null) {
                    $durationMin = ($km * $paceSec) / 60.0;
                } elseif ($km > 0.0) {
                    $durationMin = $km * 6.0;
                }
            }

            $load = 0.0;
            if ($durationMin !== null && $durationMin > 0) {
                $factor = $factorForRunType((string) ($log->getRunType() ?? ''));
                $load = round($durationMin * $factor, 1);
            }

            if ($load <= 0) {
                continue;
            }

            if (!isset($dailyLoads[$date])) {
                $dailyLoads[$date] = 0.0;
            }
            $dailyLoads[$date] += $load;
        }

        if (empty($dailyLoads)) {
            return [
                'hasData' => false,
                'statusKey' => 'none',
                'statusLabel' => 'Pas de donnees',
                'statusColor' => 'var(--text-muted)',
                'acute' => 0.0,
                'chronic' => 0.0,
                'ratio' => null,
                'deltaPct' => 0,
                'recommendation' => 'Ajoute quelques sorties pour activer le suivi de charge.',
                'weekly' => [],
            ];
        }

        $today = (new \DateTimeImmutable('now'))->setTime(0, 0, 0);
        $acute = 0.0;
        $chronicTotal = 0.0;

        foreach ($dailyLoads as $date => $load) {
            try {
                $d = (new \DateTimeImmutable($date))->setTime(0, 0, 0);
            } catch (\Throwable) {
                continue;
            }

            $daysAgo = (int) floor(($today->getTimestamp() - $d->getTimestamp()) / 86400);
            if ($daysAgo < 0 || $daysAgo > 27) {
                continue;
            }

            $chronicTotal += $load;
            if ($daysAgo <= 6) {
                $acute += $load;
            }
        }

        $chronic = $chronicTotal / 4.0;
        $ratio = $chronic > 0 ? round($acute / $chronic, 2) : null;
        $deltaPct = $chronic > 0 ? (int) round((($acute - $chronic) / $chronic) * 100) : 0;

        $statusKey = 'initial';
        $statusLabel = 'Initialisation';
        $statusColor = 'var(--accent2)';
        $recommendation = 'Continue regulierement pour stabiliser ta charge de reference.';

        if ($ratio !== null) {
            if ($ratio < 0.8) {
                $statusKey = 'under';
                $statusLabel = 'Sous-charge';
                $statusColor = 'var(--z2)';
                $recommendation = 'Tu peux ajouter une seance facile ou un peu de volume progressif.';
            } elseif ($ratio <= 1.3) {
                $statusKey = 'balanced';
                $statusLabel = 'Equilibre';
                $statusColor = 'var(--z1)';
                $recommendation = 'Charge bien equilibree: garde le cap et privilegie la regularite.';
            } elseif ($ratio <= 1.5) {
                $statusKey = 'watch';
                $statusLabel = 'Vigilance';
                $statusColor = 'var(--z3)';
                $recommendation = 'Legere hausse de charge: conserve une seance facile de recuperation.';
            } else {
                $statusKey = 'high';
                $statusLabel = 'Surcharge';
                $statusColor = 'var(--accent3)';
                $recommendation = 'Hausse trop rapide: allege 24-48h et evite une grosse seance intense.';
            }
        }

        $monday = $today->modify('monday this week');
        $weekly = [];
        for ($offset = 7; $offset >= 0; $offset--) {
            $start = $monday->modify(sprintf('-%d week', $offset));
            $end = $start->modify('+6 day');
            $sum = 0.0;

            foreach ($dailyLoads as $date => $load) {
                try {
                    $d = (new \DateTimeImmutable($date))->setTime(0, 0, 0);
                } catch (\Throwable) {
                    continue;
                }

                if ($d >= $start && $d <= $end) {
                    $sum += $load;
                }
            }

            $weekly[] = [
                'label' => $start->format('d/m'),
                'load' => round($sum, 1),
            ];
        }

        return [
            'hasData' => true,
            'statusKey' => $statusKey,
            'statusLabel' => $statusLabel,
            'statusColor' => $statusColor,
            'acute' => round($acute, 1),
            'chronic' => round($chronic, 1),
            'ratio' => $ratio,
            'deltaPct' => $deltaPct,
            'recommendation' => $recommendation,
            'weekly' => $weekly,
        ];
    }

    /**
     * @param array<int, RunLog> $logs
     * @return array<int, array{ok:bool,title:string,msg:string}>
     */
    private function buildCoherenceAlerts(array $logs): array
    {
        $alerts = [];

        $nonRace = array_values(array_filter($logs, function (RunLog $log): bool {
            return $log->getDate() !== '' && $this->paceToSeconds($log->getAllure()) !== null && strtoupper((string) ($log->getRunType() ?? '')) !== 'RACE';
        }));
        usort($nonRace, static fn (RunLog $a, RunLog $b) => strcmp($a->getDate(), $b->getDate()));

        if (count($nonRace) >= 4) {
            $half = intdiv(count($nonRace), 2);
            $first = array_slice($nonRace, 0, $half);
            $last = array_slice($nonRace, $half);

            $firstAvg = array_sum(array_map(fn (RunLog $r): int => $this->paceToSeconds($r->getAllure()) ?? 0, $first)) / max(1, count($first));
            $lastAvg = array_sum(array_map(fn (RunLog $r): int => $this->paceToSeconds($r->getAllure()) ?? 0, $last)) / max(1, count($last));
            $delta = (int) round($firstAvg - $lastAvg);

            if ($delta > 15) {
                $alerts[] = ['ok' => true, 'title' => 'Progression allure', 'msg' => sprintf('Amelioration moyenne de %s/km entre les premieres et dernieres sorties.', substr($this->secondsToDuration($delta), 3))];
            } elseif ($delta < -15) {
                $alerts[] = ['ok' => false, 'title' => 'Progression allure', 'msg' => sprintf('Allure moyenne en baisse de %s/km sur les dernieres sorties.', substr($this->secondsToDuration(-$delta), 3))];
            } else {
                $alerts[] = ['ok' => true, 'title' => 'Progression allure', 'msg' => 'Allure globalement stable sur la periode recente.'];
            }
        }

        $efBpms = [];
        foreach ($logs as $log) {
            $type = strtoupper((string) ($log->getRunType() ?? ''));
            if ($type === 'EF' && $log->getBpm() !== null) {
                $efBpms[] = (int) $log->getBpm();
            }
        }
        if (count($efBpms) >= 2) {
            $alerts[] = [
                'ok' => true,
                'title' => 'BPM endurance fondamentale',
                'msg' => sprintf('Plage observee: %d-%d bpm sur %d sortie(s) EF.', min($efBpms), max($efBpms), count($efBpms)),
            ];
        }

        $dated = array_values(array_filter($logs, static fn (RunLog $log): bool => $log->getDate() !== ''));
        usort($dated, static fn (RunLog $a, RunLog $b) => strcmp($a->getDate(), $b->getDate()));
        $maxGap = 0;
        for ($i = 1; $i < count($dated); $i++) {
            try {
                $d1 = new \DateTimeImmutable($dated[$i - 1]->getDate());
                $d2 = new \DateTimeImmutable($dated[$i]->getDate());
                $gap = (int) round(($d2->getTimestamp() - $d1->getTimestamp()) / 86400);
                if ($gap > $maxGap) {
                    $maxGap = $gap;
                }
            } catch (\Throwable) {
            }
        }
        if ($maxGap >= 10) {
            $alerts[] = ['ok' => false, 'title' => 'Coupure d\'entrainement', 'msg' => sprintf('Plus longue coupure detectee: %d jours entre deux sorties.', $maxGap)];
        }

        if (empty($alerts)) {
            $alerts[] = ['ok' => true, 'title' => 'Analyse indisponible', 'msg' => 'Pas assez de donnees pour etablir des indicateurs de coherence.'];
        }

        return $alerts;
    }

    /**
     * @param array<int, RunLog> $logs
     * @return array{
     *   hasData: bool,
     *   emptyMessage: string,
     *   chart: array{paceTicks: array<int, array{t:float,label:string}>,bpmTicks: array<int, array{t:float,label:string}>,pacePoints: array<int, array{x:float,y:float}>,bpmPoints: array<int, array{x:float,y:float}>,efDots: array<int, array{x:float,paceY:float,bpmY:float}>},
     *   tableRows: array<int, array{date:string,km:string,bpm:string,allure:string,idx:string,idxColor:string,trendLabel:string,trendColor:string}>,
     *   meta: string
     * }
     */
    private function buildEfSection(array $logs): array
    {
        $efRuns = array_values(array_filter($logs, function (RunLog $log): bool {
            $type = strtoupper((string) ($log->getRunType() ?? ''));
            return $type === 'EF' && $log->getBpm() !== null && $this->paceToSeconds($log->getAllure()) !== null && $log->getDate() !== '';
        }));
        usort($efRuns, static fn (RunLog $a, RunLog $b) => strcmp($a->getDate(), $b->getDate()));

        if (count($efRuns) < 2) {
            return [
                'hasData' => false,
                'emptyMessage' => 'Pas encore assez de sorties EF avec BPM enregistre (minimum 2).',
                'chart' => ['paceTicks' => [], 'bpmTicks' => [], 'pacePoints' => [], 'bpmPoints' => [], 'efDots' => []],
                'tableRows' => [],
                'meta' => '',
            ];
        }

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

        if (empty($points)) {
            return [
                'hasData' => false,
                'emptyMessage' => 'Pas encore assez de sorties EF avec BPM enregistre (minimum 2).',
                'chart' => ['paceTicks' => [], 'bpmTicks' => [], 'pacePoints' => [], 'bpmPoints' => [], 'efDots' => []],
                'tableRows' => [],
                'meta' => '',
            ];
        }

        $paces = array_map(static fn (array $p): int => (int) $p['pace'], $points);
        $minP = min($paces) - 10;
        $maxP = max($paces) + 10;
        $paceRange = max(1, $maxP - $minP);

        $bpms = [];
        foreach ($points as $p) {
            if ($p['bpm'] !== null) {
                $bpms[] = (int) $p['bpm'];
            }
        }
        $minB = !empty($bpms) ? min($bpms) - 5 : 130;
        $maxB = !empty($bpms) ? max($bpms) + 5 : 160;
        $bpmRange = max(1, $maxB - $minB);

        $count = count($points);
        $den = max(1, $count - 1);
        $pacePoints = [];
        $bpmPoints = [];
        $efDots = [];

        foreach ($points as $i => $p) {
            $x = $i / $den;
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
        if (!empty($bpms)) {
            foreach ([0.0, 0.5, 1.0] as $t) {
                $bVal = (int) round($minB + $t * ($maxB - $minB));
                $bpmTicks[] = ['t' => $t, 'label' => (string) $bVal];
            }
        }

        // ── BPM trend (EF runs only, chronological) ──────────────
        $efBpmTrend = [];
        $bpmWindow = [];
        foreach ($efRuns as $run) {
            $bpmVal = (int) $run->getBpm();
            $bpmWindow[] = $bpmVal;
            if (count($bpmWindow) > 3) {
                array_shift($bpmWindow);
            }
            $avg3 = count($bpmWindow) >= 2 ? round(array_sum($bpmWindow) / count($bpmWindow), 1) : null;
            $efBpmTrend[] = [
                'date' => $run->getDate(),
                'bpm' => $bpmVal,
                'avg3' => $avg3,
            ];
        }

        $tableRows = [];
        $prevIdx = null;
        foreach ($efRuns as $i => $run) {
            $pace = $this->paceToSeconds($run->getAllure()) ?? 0;
            $idx = round(($pace / max(1, (int) $run->getBpm())) * 100) / 100;

            $trendLabel = '—';
            $trendColor = 'var(--text-muted)';
            if ($prevIdx !== null) {
                if ($idx < $prevIdx - 0.05) {
                    $trendLabel = '↗ mieux';
                    $trendColor = '#4ade80';
                } elseif ($idx > $prevIdx + 0.05) {
                    $trendLabel = '↘ moins bien';
                    $trendColor = '#e05580';
                } else {
                    $trendLabel = '→ stable';
                    $trendColor = 'var(--text-muted)';
                }
            }

            $idxColor = 'var(--text-muted)';
            if ($i > 0) {
                $prevRunPace = $this->paceToSeconds($efRuns[$i - 1]->getAllure()) ?? 0;
                $prevRunIdx = round(($prevRunPace / max(1, (int) $efRuns[$i - 1]->getBpm())) * 100) / 100;
                if ($idx < $prevRunIdx - 0.05) {
                    $idxColor = 'var(--z1)';
                } elseif ($idx > $prevRunIdx + 0.05) {
                    $idxColor = 'var(--accent3)';
                }
            }

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

        return [
            'hasData' => true,
            'emptyMessage' => '',
            'chart' => [
                'paceTicks' => $paceTicks,
                'bpmTicks' => $bpmTicks,
                'pacePoints' => $pacePoints,
                'bpmPoints' => $bpmPoints,
                'efDots' => $efDots,
            ],
            'tableRows' => $tableRows,
            'efBpmTrend' => $efBpmTrend,
            'meta' => 'Indice aérobie = allure (sec/km) ÷ BPM · Plus il est bas, meilleure est ton efficacité aérobie à effort constant',
        ];
    }

    /**
     * @param array<int, RunLog> $logs
     * @return array{items: array<int, array{label:string,value:string,valueColor:string,meta:string}>, emptyMessage:string}
     */
    private function buildEfKpis(array $logs): array
    {
        $efRuns = array_values(array_filter($logs, function (RunLog $log): bool {
            $runType = strtoupper((string) ($log->getRunType() ?? ''));
            return $runType === 'EF' && $log->getBpm() !== null && $this->paceToSeconds($log->getAllure()) !== null && $log->getDate() !== '';
        }));

        usort($efRuns, static fn (RunLog $a, RunLog $b) => strcmp($a->getDate(), $b->getDate()));

        if (count($efRuns) < 2) {
            return [
                'items' => [],
                'emptyMessage' => 'Pas encore assez de sorties EF avec BPM enregistre (minimum 2).',
            ];
        }

        $first = $efRuns[0];
        $last = $efRuns[count($efRuns) - 1];

        $firstPace = $this->paceToSeconds($first->getAllure()) ?? 0;
        $lastPace = $this->paceToSeconds($last->getAllure()) ?? 0;
        $paceDelta = $firstPace - $lastPace;

        $firstIdx = round(($firstPace / max(1, (int) $first->getBpm())) * 100) / 100;
        $lastIdx = round(($lastPace / max(1, (int) $last->getBpm())) * 100) / 100;
        $idxDelta = $firstIdx - $lastIdx;

        $avgBpm = (int) round(array_sum(array_map(static fn (RunLog $r): int => (int) $r->getBpm(), $efRuns)) / count($efRuns));

        $paceSign = $paceDelta >= 0 ? '↗' : '↘';
        $paceColor = $paceDelta >= 0 ? 'var(--z1)' : 'var(--accent3)';
        $idxSign = $idxDelta >= 0 ? '↗' : '↘';
        $idxColor = $idxDelta >= 0 ? 'var(--z1)' : 'var(--accent3)';
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

    /**
     * @return array<int, array{statusClass:string,statusLabel:string,name:string,date:string,dist:string,obj:string,real:string}>
     */
    private function buildDashboardRacesTable(User $user): array
    {
        /** @var array<int, Race> $races */
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
        $monthly = [];
        foreach ($labels as $label) {
            $monthly[$label] = 0.0;
        }

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

        if (empty($all)) {
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

        if (empty($vals)) {
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
        // Filtrer les logs valides: date presente, allure enregistree, et pas une course (RACE)
        $valid = array_values(array_filter($logs, function (RunLog $log): bool {
            return $log->getDate() !== '' && $this->paceToSeconds($log->getAllure()) !== null && strtoupper((string) ($log->getRunType() ?? '')) !== 'RACE';
        }));

        // Trier par date decroissante (plus recent en premier)
        usort($valid, static fn (RunLog $a, RunLog $b) => strcmp($b->getDate(), $a->getDate()));
        // Prendre les 5 dernières sorties valides pour calculer la tendance récente
        $recent = array_slice($valid, 0, 5);

        if (empty($recent)) {
            return [[], 'Pas encore assez de donnees.'];
        }

        // Collecter les allures (en secondes) des 5 dernières sorties
        $paceSecList = [];
        $runsWithGap = 0;

        foreach ($recent as $log) {
            $gapSec = $this->paceToSeconds($log->getGap());
            $allureSec = $this->paceToSeconds($log->getAllure());
            $hasDplus = ($log->getDplus() ?? 0) > 0;

            // Priorité: utiliser le GAP (Gradient Adjusted Pace) si D+ est renseigné
            // Car le GAP corrige l'allure en fonction du dénivelé
            if ($gapSec !== null && $hasDplus) {
                $paceSecList[] = $gapSec;
                $runsWithGap++;
                continue;
            }

            // Sinon, utiliser l'allure brute
            if ($allureSec !== null) {
                $paceSecList[] = $allureSec;
            }
        }

        if (empty($paceSecList)) {
            return [[], 'Pas encore assez de donnees.'];
        }

        // Calculer l'allure moyenne en secondes/km
        $avgSecPerKm = array_sum($paceSecList) / count($paceSecList);
        // Distances cibles pour les projections
        $distances = [
            ['label' => '5 km', 'dist' => 5.0],
            ['label' => '10 km', 'dist' => 10.0],
            ['label' => '21 km', 'dist' => 21.1],
            ['label' => '42 km', 'dist' => 42.2],
        ];
        $colors = ['#d966e0', '#8b9cf4', '#e05580', '#4ade80'];

        $projections = [];
        foreach ($distances as $idx => $d) {
            // Projection simplifiee: allure moyenne x distance, puis facteur 1.22.
            $timeSec = (int) round($avgSecPerKm * $d['dist'] * 1.22);
            // Calculer l'allure moyenne pour cette distance
            $paceSec = (int) round($timeSec / $d['dist']);

            $projections[] = [
                'label' => $d['label'],
                'time' => $this->trimDuration($this->secondsToDuration($timeSec)),
                'pace' => $this->secondsToMmSs($paceSec),
                'color' => $colors[$idx % count($colors)],
            ];
        }

        // Construire le message de métadonnées
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
        if (!$pace || !str_contains($pace, ':')) {
            return null;
        }

        $parts = explode(':', $pace);
        if (count($parts) !== 2) {
            return null;
        }

        $m = (int) $parts[0];
        $s = (int) $parts[1];
        if ($m < 0 || $s < 0 || $s >= 60) {
            return null;
        }

        return $m * 60 + $s;
    }

    private function durationToSeconds(?string $duration): ?int
    {
        if (!$duration) {
            return null;
        }

        $parts = explode(':', $duration);
        if (count($parts) === 3) {
            return ((int) $parts[0] * 3600) + ((int) $parts[1] * 60) + (int) $parts[2];
        }
        if (count($parts) === 2) {
            return ((int) $parts[0] * 60) + (int) $parts[1];
        }

        return null;
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
