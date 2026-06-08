<?php

namespace App\Service;

use App\Entity\Race;
use App\Entity\RunLog;
use App\Entity\User;
use App\Repository\RaceRepository;
use App\Repository\RunLogRepository;

/**
 * Builds contextual running advice cards for the dashboard.
 */
final class DashboardAdviceService
{
    private const COLOR_WARNING = '#f0c040';
    private const COLOR_INFO = '#8b9cf4';
    private const COLOR_SUCCESS = '#4ade80';
    private const COLOR_RACE = '#e05580';
    private const DIST_DEFAULT = 'ta course';
    private const PACE_DEFAULT = '--:--';

    /**
     * @param RunLogRepository $runLogs Repository for run logs.
     * @param RaceRepository $races Repository for races.
     * @param PlanDetailsRepository $planDetails Repository for plan sessions.
     * @param PlanProgressRepository $planProgress Repository for plan completion state.
     * @param MeteoService $meteo Weather advice provider.
     */
    public function __construct(
        private RunLogRepository $runLogs,
        private RaceRepository $races,
        private DashboardPlannedAdviceService $plannedAdvice,
        private MeteoService $meteo,
        private DashboardAdvancedMetricsService $advancedMetrics,
    ) {}


    /**
     * Resolves weather + training advice using deterministic priority rules.
     *
    * @return array<int, array{title:string,text:string,tone:string,icon:string,color:string,badge:string,tempMin:?float,tempMax:?float}>
     */
    public function build(User $user, ?string $city = null): array
    {
        $ctx = $this->buildContext($user);
        $weatherAdvice = $this->meteo->buildDailyAdvice(city: $city);

        // Priority order keeps advice stable: plan constraints first, then races, then recent activity.
        // Race advice takes priority over planned advice when race is ≤ 6 days away,
        // to avoid "séance intense demain" when tomorrow is actually race day.
        $nextRaceDays = $ctx['nextRaceDays'];
        $raceIsClose = $nextRaceDays !== null && $nextRaceDays <= 6;

        $advice = ($raceIsClose ? $this->matchRaceAdvice($ctx) : null)
            ?? $this->matchPlannedAdvice($ctx)
            ?? $this->matchRaceAdvice($ctx)
            ?? $this->matchRecentRunAdvice($ctx)
            ?? $this->matchVolumeAdvice($ctx)
            ?? $this->buildDefaultAdvice($ctx['weekKm'], $ctx['weekCount']);

        $result = [$weatherAdvice, $advice];

        // Add coherence card only when a multi-signal situation is detected
        // AND the main advice card doesn't already address the race/tapering topic
        // (avoids duplicating "Course demain" + "Tapering en cours" simultaneously).
        $raceCardActive = $raceIsClose && $advice !== null && in_array($advice['badge'] ?? '', [
            $ctx['nextRace']?->getName() . ' · Demain',
            $ctx['nextRace']?->getName() . ' · Dans 2 jours',
            $ctx['nextRace']?->getName(),
            'Jour de course',
        ], true);

        if (!$raceCardActive) {
            $coherenceCard = $this->advancedMetrics->buildCoherenceHomeCard(
                $ctx['logs'],
                $ctx['nextRace'],
                $ctx['nextRaceDays']
            );
            if ($coherenceCard !== null) {
                $result[] = $coherenceCard;
            }
        }

        return $result;
    }

    /**
     * @return array{
     *   today:\DateTimeImmutable,
     *   todayStr:string,
     *   yesterdayStr:string,
     *   tomorrowStr:string,
     *   logs:array<int,RunLog>,
     *   races:array<int,Race>,
     *   todayRun:?RunLog,
     *   yesterdayRun:?RunLog,
     *   latestRun:?RunLog,
     *   weekKm:float,
     *   weekCount:int,
     *   daysSince:int,
     *   nextRace:?Race,
     *   nextRaceDays:?int,
    *   planned:array{pastPending:?PlanDetails,today:array<int,PlanDetails>,tomorrow:array<int,PlanDetails>}
     * }
     */
    private function buildContext(User $user): array
    {
        $today = new \DateTimeImmutable('today');
        $todayStr = $today->format('Y-m-d');
        $yesterdayStr = $today->sub(new \DateInterval('P1D'))->format('Y-m-d');
        $tomorrowStr = $today->add(new \DateInterval('P1D'))->format('Y-m-d');
        // Weekly context is computed on a rolling 7-day window ending today.
        $weekStartStr = $today->sub(new \DateInterval('P7D'))->format('Y-m-d');

        /** @var array<int, RunLog> $logs */
        $logs = $this->runLogs->findBy(['user' => $user], ['date' => 'DESC'], 200);
        /** @var array<int, Race> $races */
        $races = $this->races->findBy(['user' => $user], ['date' => 'ASC'], 50);

        $runsData = $this->extractRunsData($logs, $todayStr, $yesterdayStr, $weekStartStr);
        $nextRaceData = $this->findNextRace($races, $today);
        $planned = $this->plannedAdvice->buildPlannedContext($user, $todayStr, $tomorrowStr, $logs);

        return [
            'today' => $today,
            'todayStr' => $todayStr,
            'yesterdayStr' => $yesterdayStr,
            'tomorrowStr' => $tomorrowStr,
            'logs' => $logs,
            'races' => $races,
            'todayRun' => $runsData['todayRun'],
            'yesterdayRun' => $runsData['yesterdayRun'],
            'latestRun' => $runsData['latestRun'],
            'weekKm' => $runsData['weekKm'],
            'weekCount' => $runsData['weekCount'],
            'daysSince' => $this->daysSinceLatestRun($runsData['latestRun'], $today),
            'nextRace' => $nextRaceData['race'],
            'nextRaceDays' => $nextRaceData['days'],
            'planned' => $planned,
        ];
    }

    /**
     * @param array<int, RunLog> $logs
     * @return array{todayRun:?RunLog,yesterdayRun:?RunLog,latestRun:?RunLog,weekKm:float,weekCount:int}
     */
    private function extractRunsData(array $logs, string $todayStr, string $yesterdayStr, string $weekStartStr): array
    {
        $todayRun = null;
        $yesterdayRun = null;
        $weekKm = 0.0;
        $weekCount = 0;

        foreach ($logs as $log) {
            $date = $log->getDate();
            if ($date === $todayStr && $todayRun === null) {
                $todayRun = $log;
            }
            if ($date === $yesterdayStr && $yesterdayRun === null) {
                $yesterdayRun = $log;
            }
            if ($date >= $weekStartStr && $date <= $todayStr) {
                // Weekly load summary uses total distance and number of outings.
                $weekKm += (float) ($log->getKm() ?? 0.0);
                $weekCount++;
            }
        }

        return [
            'todayRun' => $todayRun,
            'yesterdayRun' => $yesterdayRun,
            'latestRun' => $logs[0] ?? null,
            'weekKm' => $weekKm,
            'weekCount' => $weekCount,
        ];
    }

    /** @param array<int, Race> $races @return array{race:?Race,days:?int} */
    private function findNextRace(array $races, \DateTimeImmutable $today): array
    {
        $nextRace = null;
        $nextRaceDays = null;

        foreach ($races as $race) {
            $result = trim((string) ($race->getResult() ?? ''));
            // Skip finished races, and races marked DNS (Did Not Start) or DNF (Did Not Finish):
            // they must never resurface as "next race" / "jour de course".
            if ($result !== '' || in_array($race->getDnfStatus(), ['dns', 'dnf'], true)) {
                continue;
            }

            $raceDate = \DateTimeImmutable::createFromFormat('Y-m-d', $race->getDate());
            if (!$raceDate instanceof \DateTimeImmutable) {
                continue;
            }

            $daysTo = (int) $today->diff($raceDate)->format('%r%a');
            if ($daysTo < 0) {
                continue;
            }

            $nextRace = $race;
            $nextRaceDays = $daysTo;
            break;
        }

        return ['race' => $nextRace, 'days' => $nextRaceDays];
    }

    private function daysSinceLatestRun(?RunLog $latestRun, \DateTimeImmutable $today): int
    {
        $daysSince = 99;

        if ($latestRun !== null) {
            $latestDate = \DateTimeImmutable::createFromFormat('Y-m-d', $latestRun->getDate());
            if ($latestDate instanceof \DateTimeImmutable) {
                // Absolute day gap between latest recorded run and today.
                $daysSince = (int) $latestDate->diff($today)->format('%a');
            }
        }

        return $daysSince;
    }

    /** @param array<string,mixed> $ctx @return array{title:string,text:string,tone:string,icon:string,color:string,badge:string}|null */
    private function matchPlannedAdvice(array $ctx): ?array
    {
        return $this->plannedAdvice->matchPlannedAdvice($ctx['planned'], $ctx['nextRace'], $ctx['nextRaceDays']);
    }

    /** @param array<string,mixed> $ctx @return array{title:string,text:string,tone:string,icon:string,color:string,badge:string}|null */
    private function matchRaceAdvice(array $ctx): ?array
    {
        /** @var ?Race $nextRace */
        $nextRace = $ctx['nextRace'];
        /** @var ?int $nextRaceDays */
        $nextRaceDays = $ctx['nextRaceDays'];

        if ($nextRace === null || $nextRaceDays === null) {
            return null;
        }

        return match (true) {
            $nextRaceDays === 0 => [
                'title' => 'Jour de course !',
                'text' => sprintf(
                    'C\'est le jour de %s (%s). Échauffement léger 15 min, reste bien hydraté, et bonne course !',
                    $nextRace->getName(),
                    $nextRace->getDistance() ?: self::DIST_DEFAULT
                ),
                'tone' => 'warning',
                'icon' => '🏁',
                'color' => self::COLOR_RACE,
                'badge' => $nextRace->getName(),
            ],
            $nextRaceDays === 1 => [
                'title' => 'Course demain',
                'text' => sprintf(
                    'La %s (%s) est demain. Si possible, garde la journée légère: jambes au repos, bonne hydratation et coucher un peu plus tôt.',
                    $nextRace->getName(),
                    $nextRace->getDistance() ?: self::DIST_DEFAULT
                ),
                'tone' => 'info',
                'icon' => '😴',
                'color' => self::COLOR_INFO,
                'badge' => $nextRace->getName() . ' · Demain',
            ],
            $nextRaceDays === 2 => [
                'title' => 'Course dans 2 jours',
                'text' => sprintf(
                    'La %s (%s) est dans 2 jours. Si tu as envie de sortir, 20-30 min très facile (< 9:00/km) peuvent suffire pour activer les jambes.',
                    $nextRace->getName(),
                    $nextRace->getDistance() ?: self::DIST_DEFAULT
                ),
                'tone' => 'info',
                'icon' => '🧘',
                'color' => self::COLOR_INFO,
                'badge' => $nextRace->getName() . ' · Dans 2 jours',
            ],
            $nextRaceDays <= 6 => [
                'title' => 'Course bientôt - charge réduite',
                'text' => sprintf(
                    'La %s (%s) arrive %s. Tu peux réduire un peu le volume, garder quelques accélérations courtes et alléger la sortie longue.',
                    $nextRace->getName(),
                    $nextRace->getDistance() ?: self::DIST_DEFAULT,
                    $nextRaceDays === 3 ? 'après-demain' : ('dans ' . $nextRaceDays . ' jours')
                ),
                'tone' => 'warning',
                'icon' => '⚡',
                'color' => self::COLOR_WARNING,
                'badge' => $nextRace->getName(),
            ],
            default => null,
        };
    }

    /** @param array<string,mixed> $ctx @return array{title:string,text:string,tone:string,icon:string,color:string,badge:string}|null */
    private function matchRecentRunAdvice(array $ctx): ?array
    {
        /** @var ?RunLog $todayRun */
        $todayRun = $ctx['todayRun'];
        /** @var ?RunLog $yesterdayRun */
        $yesterdayRun = $ctx['yesterdayRun'];
        /** @var int $daysSince */
        $daysSince = $ctx['daysSince'];
        /** @var ?RunLog $latestRun */
        $latestRun = $ctx['latestRun'];
        /** @var float $weekKm */
        $weekKm = $ctx['weekKm'];
        $advice = null;

        if ($todayRun !== null) {
            $advice = $this->buildTodayDoneAdvice($todayRun);
        } elseif ($latestRun === null) {
            $advice = [
                'title' => 'Bienvenue dans ton suivi',
                'text' => 'Aucune sortie enregistree pour le moment. Commence par ajouter ta premiere seance dans Logs pour lancer tes stats et conseils personnalises.',
                'tone' => 'info',
                'icon' => '👋',
                'color' => self::COLOR_INFO,
                'badge' => 'Premiere sortie',
            ];
        } elseif ($daysSince === 1 && $this->isIntenseRun($yesterdayRun)) {
            $advice = $this->buildYesterdayIntenseAdvice($yesterdayRun);
        } elseif ($daysSince >= 3) {
            $advice = $this->buildNoRunForDaysAdvice($latestRun, $daysSince);
        } elseif ($daysSince === 1 && !$this->isIntenseRun($yesterdayRun) && $weekKm < 35) {
            $advice = [
                'title' => 'Bonne journée pour courir',
                'text' => $this->buildYesterdayRunSummary($yesterdayRun) . ' Récupération correcte. Tu peux faire une sortie facile ou suivre ta séance prévue.',
                'tone' => 'encourage',
                'icon' => '👟',
                'color' => self::COLOR_SUCCESS,
                'badge' => 'Sortie facile hier',
            ];
        }

        return $advice;
    }

    /** @return array{title:string,text:string,tone:string,icon:string,color:string,badge:string} */
    private function buildTodayDoneAdvice(RunLog $todayRun): array
    {
        $summary = $this->buildRunSummary($todayRun, 'Sortie du jour');

        return [
            'title' => 'Séance effectuée',
            'text' => $summary . ' Pense à bien récupérer: hydratation et étirements.',
            'tone' => 'success',
            'icon' => '✅',
            'color' => self::COLOR_SUCCESS,
            'badge' => '',
        ];
    }

    /** @return array{title:string,text:string,tone:string,icon:string,color:string,badge:string} */
    private function buildYesterdayIntenseAdvice(?RunLog $yesterdayRun): array
    {
        $summary = $this->buildYesterdayRunSummary($yesterdayRun);
        $type = $yesterdayRun?->getRunType();
        $typeSuffix = $type ? ' (' . $type . ')' : '';

        return [
            'title' => 'Récupération recommandée',
            'text' => sprintf('%s Séance intense%s: une journée plus légère peut aider les muscles à bien récupérer.', $summary, $typeSuffix),
            'tone' => 'info',
            'icon' => '🛌',
            'color' => self::COLOR_INFO,
            'badge' => 'Sortie intense hier',
        ];
    }

    /** @return array{title:string,text:string,tone:string,icon:string,color:string,badge:string} */
    private function buildNoRunForDaysAdvice(?RunLog $latestRun, int $daysSince): array
    {
        $lastDate = $this->formatRunDate($latestRun);
        $lastKm = $latestRun?->getKm() !== null ? number_format((float) $latestRun->getKm(), 1, '.', '') . ' km' : null;
        $lastRunDetails = 'Dernière sortie non détaillée';
        if ($lastKm !== null && $lastDate !== null) {
            $lastRunDetails = sprintf('Derniere sortie: %s le %s', $lastKm, $lastDate);
        } elseif ($lastKm !== null) {
            $lastRunDetails = sprintf('Derniere sortie: %s', $lastKm);
        } elseif ($lastDate !== null) {
            $lastRunDetails = sprintf('Derniere sortie le %s', $lastDate);
        }

        return [
            'title' => 'Envie de bouger en douceur ?',
            'text' => sprintf('%s. Cela fait %d jours sans courir: si tu en as l\'envie, une sortie facile de 45 à 60 min peut faire du bien.', $lastRunDetails, $daysSince),
            'tone' => 'encourage',
            'icon' => '🚀',
            'color' => self::COLOR_SUCCESS,
            'badge' => $daysSince . ' jours sans sortie',
        ];
    }

    private function buildYesterdayRunSummary(?RunLog $run): string
    {
        return $this->buildRunSummary($run, 'Hier');
    }

    private function buildRunSummary(?RunLog $run, string $label): string
    {
        $distance = $run?->getKm() !== null ? number_format((float) $run->getKm(), 1, '.', '') . ' km' : null;
        $pace = trim((string) ($run?->getAllure() ?? ''));
        $paceLabel = ($pace !== '' && $pace !== self::PACE_DEFAULT) ? ($pace . '/km') : null;
        $summary = sprintf('%s: sortie enregistree.', $label);

        if ($distance !== null && $paceLabel !== null) {
            $summary = sprintf('%s: %s a %s.', $label, $distance, $paceLabel);
        } elseif ($distance !== null) {
            $summary = sprintf('%s: %s.', $label, $distance);
        } elseif ($paceLabel !== null) {
            $summary = sprintf('%s: allure moyenne %s.', $label, $paceLabel);
        }

        return $summary;
    }

    private function formatRunDate(?RunLog $run): ?string
    {
        $date = trim((string) ($run?->getDate() ?? ''));
        if ($date === '') {
            return null;
        }

        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return $dt instanceof \DateTimeImmutable ? $dt->format('d/m/Y') : $date;
    }

    /** @param array<string,mixed> $ctx @return array{title:string,text:string,tone:string,icon:string,color:string,badge:string}|null */
    private function matchVolumeAdvice(array $ctx): ?array
    {
        /** @var float $weekKm */
        $weekKm = $ctx['weekKm'];
        /** @var int $weekCount */
        $weekCount = $ctx['weekCount'];

        if ($weekKm < 35) {
            return null;
        }

        // Above 35 km/week we display a dedicated high-load warning card.

        return [
            'title' => 'Charge elevee cette semaine',
            'text' => sprintf('%.1f km en %d sorties cette semaine. Bonne charge: ecoute ton corps et prends du repos si besoin.', $weekKm, $weekCount),
            'tone' => 'warning',
            'icon' => '⚠️',
            'color' => self::COLOR_WARNING,
            'badge' => number_format($weekKm, 0, '.', '') . ' km / 7j',
        ];
    }

    /** @return array{title:string,text:string,tone:string,icon:string,color:string,badge:string} */
    private function buildDefaultAdvice(float $weekKm, int $weekCount): array
    {
        $text = 'Aucune sortie cette semaine: si tu en as l\'envie, une reprise douce peut faire du bien.';
        if ($weekKm > 0) {
            $plural = $weekCount > 1 ? 's' : '';
            $text = sprintf('%.1f km en %d sortie%s cette semaine. Continue sur ta lancee !', $weekKm, $weekCount, $plural);
        }

        return [
            'title' => 'Plan du jour',
            'text' => $text,
            'tone' => 'info',
            'icon' => '📅',
            'color' => self::COLOR_INFO,
            'badge' => '',
        ];
    }

    private function isIntenseRun(?RunLog $log): bool
    {
        $isIntense = false;

        if ($log !== null) {
            $type = strtoupper((string) ($log->getRunType() ?? ''));
            $isIntense = in_array($type, ['RACE', 'FC', 'FL', 'TEMPO', 'T'], true);

            if (!$isIntense) {
                $allureSeconds = $this->paceToSeconds($log->getAllure());
                // Faster than 8:00/km is treated as potentially intense.
                $isIntense = $allureSeconds !== null && $allureSeconds < 480;
            }
        }

        return $isIntense;
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

}
