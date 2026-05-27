<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Plan;
use App\Entity\PlanDetails;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use App\Service\PlanSessionService;

final class PlanProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private Security $security,
        private PlanSessionService $planSessionService,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Plan
    {
        if (!$data instanceof Plan) {
            throw new \InvalidArgumentException('Expected Plan entity.');
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Authentication required.');
        }

        $isCreate = null === $data->getId();

        $data->setUser($user);
        if ($isCreate && strtolower(trim($data->getName())) === 'starter') {
            $data->setDashboardTracked(false);
        }
        $this->em->persist($data);
        $this->em->flush();

        if ($isCreate) {
            $sessions = $this->sessionsForPlan($data);
            $weekIndexByMonday = $this->buildTrainingWeekIndexByMonday($sessions);

            foreach ($sessions as $index => $session) {
                $sessionDate = $this->toDate($session['date']);

                $detail = new PlanDetails();
                $detail->setUser($user);
                $detail->setPlan($data);
                $detail->setPosition($index + 1);
                $detail->setSem($this->resolveSem($session, $sessionDate, $weekIndexByMonday));
                $detail->setSessionDate($sessionDate);
                $detail->setFormat($session['format']);
                $detail->setSessionType($session['sessionType'] ?? null);
                $detail->setPe($session['pe']);
                $detail->setTotalMin($session['totalMin']);
                $detail->setIsOptional($session['isOptional']);
                $detail->setIsDone(false);
                $this->em->persist($detail);
            }
            $this->em->flush();
        }

        return $data;
    }

    /** @return array<int, array{sem:int, date:?string, format:string, sessionType:?string, pe:?string, totalMin:?int, isOptional:bool}> */
    private function sessionsForPlan(Plan $plan): array
    {
        return $this->planSessionService->getSessionsForPlan($plan);
    }

    private function toDate(?string $value): ?\DateTimeImmutable
    {
        if (!$value) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<int, array{sem:int, date:?string, format:string, sessionType:?string, pe:?string, totalMin:?int, isOptional:bool}> $sessions
     * @return array<string, int>
     */
    private function buildTrainingWeekIndexByMonday(array $sessions): array
    {
        $mondayKeys = [];

        foreach ($sessions as $session) {
            $date = $this->toDate($session['date']);
            if (!$date) {
                continue;
            }

            $mondayKeys[] = $date->setTime(0, 0)->modify('monday this week')->format('Y-m-d');
        }

        $uniqueMondayKeys = array_values(array_unique($mondayKeys));
        sort($uniqueMondayKeys, \SORT_STRING);

        $weekIndexByMonday = [];
        foreach ($uniqueMondayKeys as $idx => $mondayKey) {
            $weekIndexByMonday[$mondayKey] = $idx + 1;
        }

        return $weekIndexByMonday;
    }

    /**
    * @param array{sem:int, date:?string, format:string, sessionType:?string, pe:?string, totalMin:?int, isOptional:bool} $session
     */
    private function resolveSem(array $session, ?\DateTimeImmutable $sessionDate, array $weekIndexByMonday): ?int
    {
        if ($sessionDate) {
            $mondayKey = $sessionDate->setTime(0, 0)->modify('monday this week')->format('Y-m-d');
            if (array_key_exists($mondayKey, $weekIndexByMonday)) {
                return $weekIndexByMonday[$mondayKey];
            }
        }

        return $session['sem'] ?? null;
    }
}

