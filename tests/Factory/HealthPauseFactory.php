<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\HealthPause;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final class HealthPauseFactory
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function createActive(User $user, ?string $type = null, ?int $estimatedDays = null): HealthPause
    {
        $pause = (new HealthPause())
            ->setUser($user)
            ->setStartedAt((new \DateTimeImmutable('today'))->format('Y-m-d'))
            ->setType($type)
            ->setEstimatedDays($estimatedDays)
            ->setResumedAt(null);

        $this->entityManager->persist($pause);
        $this->entityManager->flush();

        return $pause;
    }

    public function createResumed(User $user, string $resumedAt): HealthPause
    {
        $pause = (new HealthPause())
            ->setUser($user)
            ->setStartedAt((new \DateTimeImmutable($resumedAt))->modify('-7 days')->format('Y-m-d'))
            ->setType(null)
            ->setEstimatedDays(null)
            ->setResumedAt($resumedAt);

        $this->entityManager->persist($pause);
        $this->entityManager->flush();

        return $pause;
    }
}
