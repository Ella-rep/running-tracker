<?php

namespace App\Service;

use App\Entity\PlanDetails;
use App\Entity\Race;
use App\Entity\RunLog;
use App\Entity\User;
use App\Repository\PlanDetailsRepository;
use App\Repository\PlanProgressRepository;

/**
 * Encapsulates planned-session context and advice generation for dashboard cards.
 */
final class DashboardPlannedAdviceService
{
    private const COLOR_WARNING = '#f0c040';
    private const COLOR_INFO = '#8b9cf4';

    public function __construct(
        private PlanDetailsRepository $planDetails,
        private PlanProgressRepository $planProgress,
    ) {
    }

    /**
     * @param array<int,RunLog> $logs
     * @return array{pastPending:?PlanDetails,today:array<int,PlanDetails>,tomorrow:array<int,PlanDetails>}
     */
    public function buildPlannedContext(User $user, string $todayStr, string $tomorrowStr, array $logs = []): array
    {
        $doneByProgress = $this->buildDoneByProgressMap($user);
        $validatedByLog = $this->buildValidatedByLogMap($logs);

        $rows = $this->planDetails->findBy(['user' => $user], ['sessionDate' => 'ASC', 'position' => 'ASC']);

        $pastPending = null;
        $today = [];
        $tomorrow = [];

        foreach ($rows as $row) {
            $date = $row->getSessionDate()?->format('Y-m-d');
            if ($date === null) {
                continue;
            }

            if ($this->isPastPendingSession($row, $date, $todayStr, $doneByProgress, $validatedByLog, $pastPending)) {
                $pastPending = $row;
            }

            if ($date === $todayStr) {
                $today[] = $row;
                continue;
            }

            if ($date === $tomorrowStr) {
                $tomorrow[] = $row;
            }
        }

        usort($today, static fn (PlanDetails $a, PlanDetails $b): int => ($a->getPosition() <=> $b->getPosition()));
        usort($tomorrow, static fn (PlanDetails $a, PlanDetails $b): int => ($a->getPosition() <=> $b->getPosition()));

        return ['pastPending' => $pastPending, 'today' => $today, 'tomorrow' => $tomorrow];
    }

    /**
     * @param array{pastPending:?PlanDetails,today:array<int,PlanDetails>,tomorrow:array<int,PlanDetails>} $planned
     * @return array{title:string,text:string,tone:string,icon:string,color:string,badge:string,actionType?:string,actionLabel?:string,actionPlanId?:int,actionSessionIndex?:int}|null
     */
    public function matchPlannedAdvice(array $planned, ?Race $nextRace, ?int $nextRaceDays): ?array
    {
        if ($planned['pastPending'] instanceof PlanDetails) {
            return $this->buildPastPendingAdvice($planned['pastPending']);
        }

        return $this->matchTodayOrTomorrowAdvice($planned, $nextRace, $nextRaceDays);
    }

    /**
     * @param array{pastPending:?PlanDetails,today:array<int,PlanDetails>,tomorrow:array<int,PlanDetails>} $planned
     * @return array{title:string,text:string,tone:string,icon:string,color:string,badge:string}|null
     */
    private function matchTodayOrTomorrowAdvice(array $planned, ?Race $nextRace, ?int $nextRaceDays): ?array
    {
        if ($planned['today'] !== []) {
            return $this->buildTodayAdvice($planned['today'], $nextRace, $nextRaceDays);
        }

        if ($planned['tomorrow'] !== []) {
            return $this->buildTomorrowAdvice($planned['tomorrow']);
        }

        return null;
    }

    /** @return array<string,array<int,bool>> */
    private function buildDoneByProgressMap(User $user): array
    {
        $doneByProgress = [];
        $progressRows = $this->planProgress->findBy([
            'user' => $user,
            'done' => true,
        ]);

        foreach ($progressRows as $progress) {
            $planKey = (string) $progress->getPlanKey();
            if (!isset($doneByProgress[$planKey])) {
                $doneByProgress[$planKey] = [];
            }

            $doneByProgress[$planKey][$progress->getSessionIndex()] = true;
        }

        return $doneByProgress;
    }

    /** @param array<int,RunLog> $logs @return array<int,bool> */
    private function buildValidatedByLogMap(array $logs): array
    {
        $validatedByLog = [];
        foreach ($logs as $log) {
            if (!$log instanceof RunLog) {
                continue;
            }

            $plannedSessionId = $log->getPlannedSession()?->getId();
            if ($plannedSessionId !== null) {
                $validatedByLog[$plannedSessionId] = true;
            }
        }

        return $validatedByLog;
    }

    /**
     * @param array<string,array<int,bool>> $doneByProgress
     * @param array<int,bool> $validatedByLog
     */
    private function isPastPendingSession(
        PlanDetails $row,
        string $date,
        string $todayStr,
        array $doneByProgress,
        array $validatedByLog,
        ?PlanDetails $currentPastPending
    ): bool {
        $isCandidate = $this->isUnvalidatedPastSession($row, $date, $todayStr, $doneByProgress, $validatedByLog);
        if (!$isCandidate) {
            return false;
        }

        return $this->isMoreRecentPending($row, $currentPastPending);
    }

    /**
     * @param array<string,array<int,bool>> $doneByProgress
     * @param array<int,bool> $validatedByLog
     */
    private function isUnvalidatedPastSession(
        PlanDetails $row,
        string $date,
        string $todayStr,
        array $doneByProgress,
        array $validatedByLog
    ): bool {
        if ($date >= $todayStr || $row->isDone() || $row->isCancelled()) {
            return false;
        }

        $rowId = $row->getId();
        if ($rowId !== null && isset($validatedByLog[$rowId])) {
            return false;
        }

        $sessionIndex = max(0, $row->getPosition() - 1);
        $planId = $row->getPlan()->getId();
        $planKey = is_int($planId) ? (string) $planId : '';

        return $planKey === '' || !isset($doneByProgress[$planKey][$sessionIndex]);
    }

    private function isMoreRecentPending(PlanDetails $row, ?PlanDetails $currentPastPending): bool
    {
        if ($currentPastPending === null) {
            return true;
        }

        $currentDate = $currentPastPending->getSessionDate();
        $rowDate = $row->getSessionDate();

        return $currentDate === null || ($rowDate !== null && $rowDate > $currentDate);
    }

    /** @return array{title:string,text:string,tone:string,icon:string,color:string,badge:string,actionType:string,actionLabel:string,actionPlanId:int,actionSessionIndex:int} */
    private function buildPastPendingAdvice(PlanDetails $session): array
    {
        $pendingDate = $session->getSessionDate()?->format('d/m');

        return [
            'title' => 'Séance passée non validée',
            'text' => 'Si vous avez effectué cette séance, vous pouvez la cocher quand vous avez un moment.',
            'tone' => 'warning',
            'icon' => '☑️',
            'color' => self::COLOR_WARNING,
            'badge' => $pendingDate ? ('Depuis le ' . $pendingDate) : 'En retard',
            'actionType' => 'openPlanSession',
            'actionLabel' => 'Aller valider la séance',
            'actionPlanId' => (int) $session->getPlan()->getId(),
            'actionSessionIndex' => max(0, $session->getPosition() - 1),
        ];
    }

    /** @param array<int,PlanDetails> $todaySessions @return array{title:string,text:string,tone:string,icon:string,color:string,badge:string} */
    private function buildTodayAdvice(array $todaySessions, ?Race $nextRace, ?int $nextRaceDays): array
    {
        $raceHintRunRace='';
        $raceHintRun = ' Pense aussi à vérifier la météo avant de partir.';
        $raceHintPostRun = ' Pense à bien récupérer.';
        if ($nextRace !== null && $nextRaceDays !== null && $nextRaceDays <= 2) {
            $dist = $nextRace->getDistance() ?: 'course';
            $raceHintRunRace = sprintf(' Focus course: %s (%s) approche.', $nextRace->getName(), $dist);
        }

        foreach($todaySessions as $session) {
            if($session->isDone()) {
                return [
                    'title' => 'Séance du jour validée',
                    'text' => 'Bravo pour ta séance du jour !' . ($nextRace !== null ? $raceHintRunRace : $raceHintPostRun),
                    'tone' => 'success',
                    'icon' => '✅',
                    'color' => '#40c040',
                    'badge' => 'Aujourd\'hui',
                ];
            }
        }
        $todayCount = count($todaySessions);
        $labelText = $this->plannedSessionsLabelList($todaySessions);

        return [
            'title' => 'Séance planifiée aujourd\'hui',
            'text' => sprintf(
                '%d séance%s planifiée%s aujourd\'hui: %s.%s',
                $todayCount,
                $todayCount > 1 ? 's' : '',
                $todayCount > 1 ? 's' : '',
                $labelText,
                $raceHintRun
            ),
            'tone' => 'info',
            'icon' => '📅',
            'color' => self::COLOR_INFO,
            'badge' => 'Aujourd\'hui',
        ];
    }

    /** @param array<int,PlanDetails> $tomorrowSessions @return array{title:string,text:string,tone:string,icon:string,color:string,badge:string} */
    private function buildTomorrowAdvice(array $tomorrowSessions): array
    {
        if ($this->containsIntenseSession($tomorrowSessions)) {
            return [
                'title' => 'Demain séance intense',
                'text' => 'Demain une séance intense est prévue, une journée plus légère aujourd\'hui peut aider à bien récupérer.',
                'tone' => 'warning',
                'icon' => '⚡',
                'color' => self::COLOR_WARNING,
                'badge' => 'Demain',
            ];
        }

        return [
            'title' => 'Demain séance douce',
            'text' => 'Demain séance douce prévue, profite d\'aujourd\'hui pour une récupération active et adapte toi à la météo.',
            'tone' => 'info',
            'icon' => '🌤️',
            'color' => self::COLOR_INFO,
            'badge' => 'Demain',
        ];
    }

    /** @param array<int,PlanDetails> $sessions */
    private function containsIntenseSession(array $sessions): bool
    {
        foreach ($sessions as $session) {
            if ($this->isIntensePlannedSession($session)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int,PlanDetails> $sessions */
    private function plannedSessionsLabelList(array $sessions): string
    {
        $labels = [];
        foreach ($sessions as $session) {
            $label = trim((string) $session->getFormat());
            $finalLabel = $label !== '' ? $label : 'planifiee';

            if (!in_array($finalLabel, $labels, true)) {
                $labels[] = $finalLabel;
            }
        }

        if ($labels === []) {
            return 'seance planifiee';
        }

        return implode(' | ', $labels);
    }

    private function isIntensePlannedSession(PlanDetails $session): bool
    {
        $pe = trim((string) ($session->getPe() ?? ''));
        if (preg_match('/^(\d+)\/10$/', $pe, $matches) === 1 && (int) $matches[1] >= 5) {
            return true;
        }

        $format = strtoupper((string) $session->getFormat());

        return str_contains($format, '@Z5')
            || str_contains($format, '@Z4')
            || str_contains($format, '10KM')
            || str_contains($format, '5KM')
            || str_contains($format, 'RACE');
    }
}
