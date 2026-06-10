<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\HealthPause;
use App\Entity\User;
use App\Enum\PauseType;
use App\Repository\HealthPauseRepository;
use Doctrine\ORM\EntityManagerInterface;

final class HealthPauseService
{
    public function __construct(
        private HealthPauseRepository $repository,
        private EntityManagerInterface $em,
    ) {
    }

    public function activate(User $user, ?string $type, ?int $estimatedDays): HealthPause
    {
        $existing = $this->repository->findBy(['user' => $user]);
        foreach ($existing as $old) {
            $this->em->remove($old);
        }
        if (count($existing) > 0) {
            $this->em->flush();
        }

        $resolvedType = null;
        if ($type !== null) {
            $enumCase = PauseType::tryFrom($type);
            $resolvedType = $enumCase !== null ? $enumCase->value : PauseType::Other->value;
        }

        $pause = (new HealthPause())
            ->setUser($user)
            ->setStartedAt((new \DateTimeImmutable('today'))->format('Y-m-d'))
            ->setType($resolvedType)
            ->setEstimatedDays($estimatedDays)
            ->setResumedAt(null);

        $this->em->persist($pause);
        $this->em->flush();

        return $pause;
    }

    public function resume(User $user): void
    {
        $pause = $this->repository->findActiveByUser($user);
        if ($pause === null) {
            return;
        }

        $pause->setResumedAt((new \DateTimeImmutable('today'))->format('Y-m-d'));
        $this->em->flush();
    }

    /**
     * @return array{active:bool,startedAt:?string,type:?string,estimatedDays:?int,resumedAt:?string,recoveryWindowActive:bool,recoveryDaysRemaining:int}
     */
    public function getStatus(User $user): array
    {
        $pause = $this->repository->findBy(['user' => $user]);
        $pause = $pause[0] ?? null;

        if ($pause === null) {
            return [
                'active' => false,
                'startedAt' => null,
                'type' => null,
                'estimatedDays' => null,
                'resumedAt' => null,
                'recoveryWindowActive' => false,
                'recoveryDaysRemaining' => 0,
            ];
        }

        return [
            'active' => $pause->isActive(),
            'startedAt' => $pause->getStartedAt(),
            'type' => $pause->getType(),
            'estimatedDays' => $pause->getEstimatedDays(),
            'resumedAt' => $pause->getResumedAt(),
            'recoveryWindowActive' => $pause->isInRecoveryWindow(),
            'recoveryDaysRemaining' => $pause->getRecoveryDaysRemaining(),
        ];
    }
}
