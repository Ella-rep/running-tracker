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
        $this->em->persist($data);
        $this->em->flush();

        if ($isCreate) {
            $sessions = $this->sessionsForPlan($data);
            $planStartMonday = $this->getPlanStartMonday($sessions);

            foreach ($sessions as $index => $session) {
                $sessionDate = $this->toDate($session['date']);

                $detail = new PlanDetails();
                $detail->setUser($user);
                $detail->setPlan($data);
                $detail->setPosition($index + 1);
                $detail->setSem($this->resolveSem($session, $sessionDate, $planStartMonday));
                $detail->setSessionDate($sessionDate);
                $detail->setFormat($session['format']);
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

    /** @return array<int, array{sem:int, date:?string, format:string, pe:?string, totalMin:?int, isOptional:bool}> */
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

    /** @param array<int, array{sem:int, date:?string, format:string, pe:?string, totalMin:?int, isOptional:bool}> $sessions */
    private function getPlanStartMonday(array $sessions): ?\DateTimeImmutable
    {
        $firstDate = null;

        foreach ($sessions as $session) {
            $date = $this->toDate($session['date']);
            if (!$date) {
                continue;
            }

            if ($firstDate === null || $date < $firstDate) {
                $firstDate = $date;
            }
        }

        return $firstDate?->setTime(0, 0)->modify('monday this week');
    }

    /**
     * @param array{sem:int, date:?string, format:string, pe:?string, totalMin:?int, isOptional:bool} $session
     */
    private function resolveSem(array $session, ?\DateTimeImmutable $sessionDate, ?\DateTimeImmutable $planStartMonday): ?int
    {
        if ($sessionDate && $planStartMonday) {
            $sessionMonday = $sessionDate->setTime(0, 0)->modify('monday this week');
            $daysDiff = (int) $planStartMonday->diff($sessionMonday)->format('%r%a');

            return intdiv(max(0, $daysDiff), 7) + 1;
        }

        return $session['sem'] ?? null;
    }
}

