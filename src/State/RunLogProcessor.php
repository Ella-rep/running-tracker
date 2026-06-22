<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\PlanProgress;
use App\Entity\RunLog;
use App\Entity\User;
use App\Repository\PlanProgressRepository;
use App\Service\GamificationWidgetService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class RunLogProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private PlanProgressRepository $planProgressRepository,
        private EntityManagerInterface $em,
        private GamificationWidgetService $gamification,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): RunLog
    {
        if (!$data instanceof RunLog) {
            throw new \InvalidArgumentException('RunLog expected.');
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Authentication required.');
        }

        $data->setUser($user);
        $this->computeDerivedMetrics($data);
        $this->syncPlannedSessionProgress($data, $user);
        $this->em->persist($data);
        $this->em->flush();

        if ($user->isRpgMode()) {
            $this->gamification->recalculate($user);
            $this->gamification->updateQuestProgress($user, $data);
        }

        return $data;
    }

    private function computeDerivedMetrics(RunLog $log): void
    {
        $km = (float) ($log->getKm() ?? 0.0);
        $durationSec = $this->durationToSeconds($log->getDuration());

        if ($km > 0 && $durationSec !== null && $durationSec > 0) {
            $allureSec = (int) round($durationSec / $km);
            $log->setAllure($this->secondsToMinSec($allureSec));

            $dplus = (int) ($log->getDplus() ?? 0);
            if ($dplus > 0) {
                $grade = $dplus / ($km * 1000.0);
                $gapSec = (int) round($allureSec - ($grade * 7.5 * $allureSec));
                $log->setGap($gapSec > 0 ? $this->secondsToMinSec($gapSec) : null);
            } else {
                $log->setGap(null);
            }

            return;
        }

        $log->setAllure(null);
        $log->setGap(null);
    }

    private function syncPlannedSessionProgress(RunLog $log, User $user): void
    {
        $plannedSession = $log->getPlannedSession();
        if ($plannedSession && $plannedSession->getUser()->getId() !== $user->getId()) {
            $log->setPlannedSession(null);
            return;
        }

        if (!$plannedSession) {
            return;
        }

        $plannedSession->setIsDone(true);

        // Si la date réelle de la sortie diffère de la date planifiée, on met à jour automatiquement
        $logDate = $log->getDate();
        if (is_string($logDate) && $logDate !== '') {
            $realizedDate = \DateTimeImmutable::createFromFormat('Y-m-d', $logDate);
            if ($realizedDate instanceof \DateTimeImmutable) {
                $plannedDate = $plannedSession->getSessionDate();
                $plannedDateStr = $plannedDate instanceof \DateTimeInterface
                    ? $plannedDate->format('Y-m-d')
                    : null;
                if ($plannedDateStr !== $realizedDate->format('Y-m-d')) {
                    $plannedSession->setSessionDate($realizedDate);
                }
            }
        }

        $plan = $plannedSession->getPlan();
        $planKey = (string) $plan->getId();
        $sessionIndex = max(0, $plannedSession->getPosition() - 1);

        $existing = $this->planProgressRepository->findOneBy([
            'user' => $user,
            'planKey' => $planKey,
            'sessionIndex' => $sessionIndex,
        ]);

        if ($existing instanceof PlanProgress) {
            $existing->setDone(true);
            return;
        }

        $progress = new PlanProgress();
        $progress
            ->setUser($user)
            ->setPlanKey($planKey)
            ->setSessionIndex($sessionIndex)
            ->setDone(true);
        $this->em->persist($progress);
    }

    private function durationToSeconds(?string $duration): ?int
    {
        if (!is_string($duration)) {
            return null;
        }

        $raw = trim($duration);
        if ($raw === '') {
            return null;
        }

        $candidates = [$raw];
        if (str_contains($raw, '/')) {
            foreach (explode('/', $raw) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $candidates[] = $part;
                }
            }
        }

        $seconds = null;
        foreach (array_unique($candidates) as $value) {
            if (preg_match("/^(\\d+)[\\'’]?$/", $value, $m) === 1) {
                $seconds = (int) $m[1] * 60;
                break;
            }

            if (preg_match('/^(\d{1,3}):([0-5]\d):([0-5]\d)$/', $value, $m) === 1) {
                $seconds = ((int) $m[1]) * 3600 + ((int) $m[2]) * 60 + (int) $m[3];
                break;
            }

            if (preg_match('/^(\d{1,3}):([0-5]\d)$/', $value, $m) === 1) {
                $seconds = ((int) $m[1]) * 60 + (int) $m[2];
                break;
            }
        }

        return $seconds;
    }

    private function secondsToMinSec(int $seconds): string
    {
        $m = intdiv(max(0, $seconds), 60);
        $s = max(0, $seconds) % 60;

        return sprintf('%02d:%02d', $m, $s);
    }
}

