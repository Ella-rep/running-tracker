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
}
