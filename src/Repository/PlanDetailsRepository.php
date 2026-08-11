<?php

namespace App\Repository;

use App\Entity\PlanDetails;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlanDetails>
 */
class PlanDetailsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanDetails::class);
    }

    /**
     * Returns completion stats based on real tracked plan sessions.
     *
     * @return array{done:int,total:int,rate:float}
     */
    public function getTrackedCompletionStats(): array
    {
        $row = $this->createQueryBuilder('d')
            ->innerJoin('d.plan', 'p')
            ->select('SUM(CASE WHEN d.isDone = true THEN 1 ELSE 0 END) AS doneCount')
            ->addSelect('COUNT(d.id) AS totalCount')
            ->andWhere('p.dashboardTracked = true')
            ->getQuery()
            ->getSingleResult();

        $done = (int) ($row['doneCount'] ?? 0);
        $total = (int) ($row['totalCount'] ?? 0);
        $rate = $total > 0 ? round(($done / $total) * 100, 1) : 0.0;

        return [
            'done' => $done,
            'total' => $total,
            'rate' => $rate,
        ];
    }

    /**
     * Returns true when another session in the same plan is already scheduled on the
     * given date. Used to avoid auto-moving a session's planned date onto a day that
     * already has a different session, which would otherwise show as a duplicate entry
     * on the calendar.
     */
    public function hasOtherSessionOnDate(PlanDetails $excluding, \DateTimeInterface $date): bool
    {
        $count = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->andWhere('d.plan = :plan')
            ->andWhere('d.user = :user')
            ->andWhere('d.sessionDate = :date')
            ->andWhere('d.id != :excludedId')
            ->setParameter('plan', $excluding->getPlan())
            ->setParameter('user', $excluding->getUser())
            ->setParameter('date', $date->setTime(0, 0, 0), 'date')
            ->setParameter('excludedId', $excluding->getId())
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $count) > 0;
    }
}
