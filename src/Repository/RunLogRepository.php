<?php
namespace App\Repository;
use App\Entity\RunLog;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use DateTimeImmutable;
class RunLogRepository extends ServiceEntityRepository {
    public function __construct(ManagerRegistry $r) { parent::__construct($r, RunLog::class); }

    /**
     * Returns a set (id => true) of PlanDetails IDs that have at least one linked RunLog for the given user.
     * @return array<int, true>
     */
    public function findLoggedDetailIds(User $user): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.plannedSession) AS detailId')
            ->where('r.user = :user')
            ->andWhere('r.plannedSession IS NOT NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getScalarResult();

        $set = [];
        foreach ($rows as $row) {
            $id = (int) $row['detailId'];
            if ($id > 0) {
                $set[$id] = true;
            }
        }
        return $set;
    }

    public function countSinceDate(string $dateYmd): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.date >= :dateYmd')
            ->setParameter('dateYmd', $dateYmd)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countDistinctUsersSinceDate(string $dateYmd): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(DISTINCT IDENTITY(r.user))')
            ->andWhere('r.date >= :dateYmd')
            ->setParameter('dateYmd', $dateYmd)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countDistinctUsersAllTime(): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(DISTINCT IDENTITY(r.user))')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function hasAnyCreatedSince(DateTimeImmutable $since): bool
    {
        $result = (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.createdAt >= :since')
            ->setParameter('since', $since)
            ->setMaxResults(1)
            ->getQuery()
            ->getSingleScalarResult();

        return $result > 0;
    }
}
