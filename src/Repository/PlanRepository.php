<?php

namespace App\Repository;

use App\Entity\Plan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Plan>
 */
class PlanRepository extends ServiceEntityRepository
{
    private const COUNT_EXPR = 'COUNT(p.id)';
    private const TRACKED_CONDITION = 'p.dashboardTracked = true';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Plan::class);
    }

    public function countAllPlans(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select(self::COUNT_EXPR)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countTrackedPlans(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select(self::COUNT_EXPR)
            ->andWhere(self::TRACKED_CONDITION)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countUntrackedPlans(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select(self::COUNT_EXPR)
            ->andWhere('p.dashboardTracked = false')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countDistinctUsersWithPlans(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(DISTINCT IDENTITY(p.user))')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Returns completion stats at plan level for tracked plans.
     * A plan is considered completed only when all its sessions are done.
     *
     * @return array{done:int,total:int,rate:float}
     */
    public function getTrackedPlanCompletionStats(): array
    {
        $total = (int) $this->createQueryBuilder('p')
            ->select(self::COUNT_EXPR)
            ->andWhere(self::TRACKED_CONDITION)
            ->getQuery()
            ->getSingleScalarResult();

        $done = (int) $this->createQueryBuilder('p')
            ->select(self::COUNT_EXPR)
            ->andWhere(self::TRACKED_CONDITION)
            ->andWhere('EXISTS (SELECT d1.id FROM App\\Entity\\PlanDetails d1 WHERE d1.plan = p)')
            ->andWhere('NOT EXISTS (SELECT d2.id FROM App\\Entity\\PlanDetails d2 WHERE d2.plan = p AND d2.isDone = false)')
            ->getQuery()
            ->getSingleScalarResult();

        $rate = $total > 0 ? round(($done / $total) * 100, 1) : 0.0;

        return [
            'done' => $done,
            'total' => $total,
            'rate' => $rate,
        ];
    }
}
