<?php

namespace App\Repository;

use App\Entity\PlanProgress;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PlanProgressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanProgress::class);
    }

    /**
     * @return array{done:int,total:int,rate:float}
     */
    public function getCompletionStats(): array
    {
        $row = $this->createQueryBuilder('p')
            ->select('SUM(CASE WHEN p.done = true THEN 1 ELSE 0 END) AS doneCount')
            ->addSelect('COUNT(p.id) AS totalCount')
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
