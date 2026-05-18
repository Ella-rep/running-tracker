<?php

namespace App\Service;

use App\Entity\Race;
use App\Entity\RunLog;
use App\Entity\User;
use App\Entity\PlanDetails;
use App\Repository\PlanDetailsRepository;
use App\Repository\PlanRepository;
use App\Repository\RaceRepository;
use App\Repository\RunLogRepository;

final class DashboardAdviceService
{
    private const COLOR_WARNING = '#f0c040';
    private const COLOR_INFO = '#8b9cf4';
    private const COLOR_SUCCESS = '#4ade80';
    private const COLOR_RACE = '#e05580';
    private const DIST_DEFAULT = 'ta course';
    private const PACE_DEFAULT = '--:--';

    public function __construct(
        private RunLogRepository $runLogs,
        private RaceRepository $races,
        private PlanRepository $plans,
        private PlanDetailsRepository $planDetails,
        private MeteoService $meteo,
    ) {}

    /**
     * @return array<int, array{title:string,text:string,tone:string,icon:string,color:string,badge:string}>
     */
    public function build(User $user, ?string $city = null): array
    {
        $ctx = $this->buildContext($user);
        $weatherAdvice = $this->meteo->buildDailyAdvice(city: $city);

        $advice = $this->matchPlannedAdvice($ctx)
            ?? $this->matchRaceAdvice($ctx)
            ?? $this->matchRecentRunAdvice($ctx)
            ?? $this->matchVolumeAdvice($ctx)
            ?? $this->buildDefaultAdvice($ctx['weekKm'], $ctx['weekCount']);

        return [$weatherAdvice, $advice];
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
     *   planned:array{pastPending:?PlanDetails,today:?PlanDetails,tomorrow:?PlanDetails}
     * }
     */
    private function buildContext(User $user): array
    {
        $today = new \DateTimeImmutable('today');
        $todayStr = $today->format('Y-m-d');
        $yesterdayStr = $today->sub(new \DateInterval('P1D'))->format('Y-m-d');
        $tomorrowStr = $today->add(new \DateInterval('P1D'))->format('Y-m-d');
        $weekStartStr = $today->sub(new \DateInterval('P7D'))->format('Y-m-d');

        /** @var array<int, RunLog> $logs */
        $logs = $this->runLogs->findBy(['user' => $user], ['date' => 'DESC'], 200);
        /** @var array<int, Race> $races */
        $races = $this->races->findBy(['user' => $user], ['date' => 'ASC'], 50);

        $runsData = $this->extractRunsData($logs, $todayStr, $yesterdayStr, $weekStartStr);
        $nextRaceData = $this->findNextRace($races, $today);
        $planned = $this->loadPlannedSessionsAroundToday($user, $todayStr, $tomorrowStr);

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
            if ($result !== '') {
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
                $daysSince = (int) $latestDate->diff($today)->format('%a');
            }
        }

        return $daysSince;
    }

    /** @param array<string,mixed> $ctx @return array{title:string,text:string,tone:string,icon:string,color:string,badge:string}|null */
    private function matchPlannedAdvice(array $ctx): ?array
    {
        $planned = $ctx['planned'];
        $nextRace = $ctx['nextRace'];
        $nextRaceDays = $ctx['nextRaceDays'];
        $advice = null;

        if ($planned['pastPending'] !== null) {
            $pendingDate = $planned['pastPending']->getSessionDate()?->format('d/m');
            $pendingPlanId = $planned['pastPending']->getPlan()->getId();
            $pendingIndex = max(0, $planned['pastPending']->getPosition() - 1);
            $advice = [
                'title' => 'Seance passee non validee',
                'text' => 'Avez vous effectue cette seance ? N\'oubliez pas de la cocher.',
                'tone' => 'warning',
                'icon' => '☑️',
                'color' => self::COLOR_WARNING,
                'badge' => $pendingDate ? ('Depuis le ' . $pendingDate) : 'En retard',
                'actionType' => 'openPlanSession',
                'actionLabel' => 'Aller valider la seance',
                'actionPlanId' => $pendingPlanId,
                'actionSessionIndex' => $pendingIndex,
            ];
        } elseif ($planned['today'] !== null) {
            $raceHint = ' Pense aussi a verifier la meteo avant de partir.';
            if ($nextRace !== null && $nextRaceDays !== null && $nextRaceDays <= 2) {
                $dist = $nextRace->getDistance() ?: 'course';
                $raceHint = sprintf(' Focus course: %s (%s) approche.', $nextRace->getName(), $dist);
            }

            $advice = [
                'title' => 'Seance planifiee aujourd\'hui',
                'text' => sprintf('Seance "%s" aujourd\'hui.%s', $this->sessionLabel($planned['today']), $raceHint),
                'tone' => 'info',
                'icon' => '📅',
                'color' => self::COLOR_INFO,
                'badge' => 'Aujourd\'hui',
            ];
        } elseif ($planned['tomorrow'] !== null) {
            $isIntense = $this->isIntensePlannedSession($planned['tomorrow']);
            $advice = $isIntense
                ? [
                    'title' => 'Demain seance intense',
                    'text' => 'Demain seance intense prevue, allez y tranquille aujourd\'hui.',
                    'tone' => 'warning',
                    'icon' => '⚡',
                    'color' => self::COLOR_WARNING,
                    'badge' => 'Demain',
                ]
                : [
                    'title' => 'Demain seance douce',
                    'text' => 'Demain seance douce prevue, profite d\'aujourd\'hui pour une recuperation active et adapte toi a la meteo.',
                    'tone' => 'info',
                    'icon' => '🌤️',
                    'color' => self::COLOR_INFO,
                    'badge' => 'Demain',
                ];
        }

        return $advice;
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
                    'C\'est le jour de %s (%s). Echauffement leger 15 min, reste bien hydrate, et bonne course !',
                    $nextRace->getName(),
                    $nextRace->getDistance() ?: self::DIST_DEFAULT
                ),
                'tone' => 'warning',
                'icon' => '🏁',
                'color' => self::COLOR_RACE,
                'badge' => $nextRace->getName(),
            ],
            $nextRaceDays === 1 => [
                'title' => 'Course demain - repos absolu',
                'text' => sprintf(
                    'La %s (%s) est demain. Aucune sortie aujourd\'hui: jambes au repos, bonne hydratation, dors tot.',
                    $nextRace->getName(),
                    $nextRace->getDistance() ?: self::DIST_DEFAULT
                ),
                'tone' => 'info',
                'icon' => '😴',
                'color' => self::COLOR_INFO,
                'badge' => $nextRace->getName() . ' J-1',
            ],
            $nextRaceDays === 2 => [
                'title' => 'Activation legere J-2',
                'text' => sprintf(
                    'J-2 avant la %s (%s). Si tu sors: 20-30 min tres facile (< 9:00/km) pour activer les jambes.',
                    $nextRace->getName(),
                    $nextRace->getDistance() ?: self::DIST_DEFAULT
                ),
                'tone' => 'info',
                'icon' => '🧘',
                'color' => self::COLOR_INFO,
                'badge' => $nextRace->getName() . ' J-2',
            ],
            $nextRaceDays <= 6 => [
                'title' => 'Course proche - charge reduite',
                'text' => sprintf(
                    'La %s (%s) arrive %s. Reduis le volume, garde quelques accelerations courtes, evite la longue.',
                    $nextRace->getName(),
                    $nextRace->getDistance() ?: self::DIST_DEFAULT,
                    $nextRaceDays === 3 ? 'apres-demain' : ('dans ' . $nextRaceDays . ' jours')
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
        } elseif ($daysSince === 1 && $this->isIntenseRun($yesterdayRun)) {
            $advice = $this->buildYesterdayIntenseAdvice($yesterdayRun);
        } elseif ($daysSince >= 3) {
            $advice = $this->buildNoRunForDaysAdvice($latestRun, $daysSince);
        } elseif ($daysSince === 1 && !$this->isIntenseRun($yesterdayRun) && $weekKm < 35) {
            $km = $yesterdayRun?->getKm() !== null ? number_format((float) $yesterdayRun->getKm(), 1, '.', '') : '?';
            $allure = $yesterdayRun?->getAllure() ?: self::PACE_DEFAULT;
            $advice = [
                'title' => 'Bonne journee pour courir',
                'text' => sprintf('Hier: %s km a %s/km (recuperation OK). Tu peux enchainer: EF ou tempo selon ton plan.', $km, $allure),
                'tone' => 'encourage',
                'icon' => '👟',
                'color' => self::COLOR_SUCCESS,
                'badge' => 'J-1 facile',
            ];
        }

        return $advice;
    }

    /** @return array{title:string,text:string,tone:string,icon:string,color:string,badge:string} */
    private function buildTodayDoneAdvice(RunLog $todayRun): array
    {
        $km = $todayRun->getKm() !== null ? number_format((float) $todayRun->getKm(), 1, '.', '') : '?';
        $allure = $todayRun->getAllure() ?: self::PACE_DEFAULT;

        return [
            'title' => 'Seance effectuee',
            'text' => sprintf('%s km a %s/km. Bien recuperer: hydratation et etirements.', $km, $allure),
            'tone' => 'success',
            'icon' => '✅',
            'color' => self::COLOR_SUCCESS,
            'badge' => '',
        ];
    }

    /** @return array{title:string,text:string,tone:string,icon:string,color:string,badge:string} */
    private function buildYesterdayIntenseAdvice(?RunLog $yesterdayRun): array
    {
        $km = $yesterdayRun?->getKm() !== null ? number_format((float) $yesterdayRun->getKm(), 1, '.', '') : '?';
        $allure = $yesterdayRun?->getAllure() ?: self::PACE_DEFAULT;
        $type = $yesterdayRun?->getRunType();
        $typeSuffix = $type ? ' (' . $type . ')' : '';

        return [
            'title' => 'Recuperation conseillee',
            'text' => sprintf('Hier: %s km a %s/km%s. Laisse tes muscles recuperer aujourd\'hui.', $km, $allure, $typeSuffix),
            'tone' => 'info',
            'icon' => '🛌',
            'color' => self::COLOR_INFO,
            'badge' => 'J-1 intense',
        ];
    }

    /** @return array{title:string,text:string,tone:string,icon:string,color:string,badge:string} */
    private function buildNoRunForDaysAdvice(?RunLog $latestRun, int $daysSince): array
    {
        $lastKm = $latestRun?->getKm() !== null ? number_format((float) $latestRun->getKm(), 1, '.', '') : '?';
        $lastDate = $latestRun?->getDate() ?: 'inconnue';

        return [
            'title' => 'Il est temps de sortir !',
            'text' => sprintf('Derniere sortie il y a %d jours (%s km le %s). Le corps est repose: une EF 45-60 min est ideale.', $daysSince, $lastKm, $lastDate),
            'tone' => 'encourage',
            'icon' => '🚀',
            'color' => self::COLOR_SUCCESS,
            'badge' => $daysSince . ' jours sans sortie',
        ];
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
        $text = 'Aucune sortie cette semaine: c\'est le bon moment pour demarrer !';
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
                    $seconds = $m * 60 + $s;
                }
            }
        }

        return $seconds;
    }

    /**
    * @return array{pastPending:?PlanDetails,today:?PlanDetails,tomorrow:?PlanDetails}
     */
    private function loadPlannedSessionsAroundToday(User $user, string $todayStr, string $tomorrowStr): array
    {
        $targetPlan = $this->resolveTargetPlan($user);
        if ($targetPlan === null) {
            return ['pastPending' => null, 'today' => null, 'tomorrow' => null];
        }

        $rows = $this->planDetails->findBy(['user' => $user, 'plan' => $targetPlan], ['position' => 'ASC']);

        $pastPending = null;
        $today = null;
        $tomorrow = null;

        foreach ($rows as $row) {
            $d = $row->getSessionDate();
            if (!$d) {
                continue;
            }

            $date = $d->format('Y-m-d');

            if ($date < $todayStr && !$row->isDone() && ($pastPending === null || $d > $pastPending->getSessionDate())) {
                $pastPending = $row;
            }

            if ($date === $todayStr && $today === null) {
                $today = $row;
            } elseif ($date === $tomorrowStr && $tomorrow === null) {
                $tomorrow = $row;
            }
        }

        return ['pastPending' => $pastPending, 'today' => $today, 'tomorrow' => $tomorrow];
    }

    private function resolveTargetPlan(User $user): ?object
    {
        $plans = $this->plans->findBy(['user' => $user], ['id' => 'ASC']);
        $latestPersonalPlan = null;
        $starterPlan = null;

        foreach ($plans as $plan) {
            if ($plan->getName() === 'starter') {
                $starterPlan = $plan;
                continue;
            }
            $latestPersonalPlan = $plan;
        }

        return $latestPersonalPlan ?? $starterPlan;
    }

    private function sessionLabel(PlanDetails $session): string
    {
        $format = trim((string) $session->getFormat());
        if ($format !== '') {
            return $format;
        }

        return 'planifiee';
    }

    private function isIntensePlannedSession(PlanDetails $session): bool
    {
        $isIntense = false;

        $pe = trim((string) ($session->getPe() ?? ''));
        if (preg_match('/^(\d+)\/10$/', $pe, $m) === 1 && (int) $m[1] >= 5) {
            $isIntense = true;
        }

        if (!$isIntense) {
            $format = strtoupper((string) $session->getFormat());
            $isIntense = str_contains($format, '@Z5')
                || str_contains($format, '@Z4')
                || str_contains($format, '10KM')
                || str_contains($format, '5KM')
                || str_contains($format, 'RACE');
        }

        return $isIntense;
    }
}
