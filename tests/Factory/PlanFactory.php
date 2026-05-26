<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\Plan;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Lightweight plan factory for integration tests.
 */
final class PlanFactory
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * Creates and persists a plan for a user.
     */
    public function createOne(User $user, string $name = 'Starter'): Plan
    {
        $plan = (new Plan())
            ->setUser($user)
            ->setName($name);

        $this->entityManager->persist($plan);
        $this->entityManager->flush();

        return $plan;
    }
}
