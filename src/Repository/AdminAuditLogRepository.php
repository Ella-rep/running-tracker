<?php

namespace App\Repository;

use App\Entity\AdminAuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use DateTimeImmutable;

class AdminAuditLogRepository extends ServiceEntityRepository
{
    private const WHERE_ACTION = 'a.action = :action';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdminAuditLog::class);
    }

    /**
     * @return list<AdminAuditLog>
     */
    public function findRecentFiltered(
        ?string $action,
        ?string $adminIdentifier,
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $to,
        int $limit,
        int $offset = 0
    ): array {
        $qb = $this->createQueryBuilder('a')
            ->orderBy('a.createdAt', 'DESC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, $limit));

        if ($action !== null && $action !== '') {
            $qb
                ->andWhere(self::WHERE_ACTION)
                ->setParameter('action', $action);
        }

        if ($adminIdentifier !== null && $adminIdentifier !== '') {
            $qb
                ->andWhere('LOWER(a.adminIdentifier) LIKE :adminIdentifier')
                ->setParameter('adminIdentifier', '%' . mb_strtolower($adminIdentifier) . '%');
        }

        if ($from instanceof DateTimeImmutable) {
            $qb
                ->andWhere('a.createdAt >= :fromDate')
                ->setParameter('fromDate', $from);
        }

        if ($to instanceof DateTimeImmutable) {
            $qb
                ->andWhere('a.createdAt <= :toDate')
                ->setParameter('toDate', $to);
        }

        return $qb->getQuery()->getResult();
    }

    public function countFiltered(
        ?string $action,
        ?string $adminIdentifier,
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $to
    ): int {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)');

        if ($action !== null && $action !== '') {
            $qb
                ->andWhere(self::WHERE_ACTION)
                ->setParameter('action', $action);
        }

        if ($adminIdentifier !== null && $adminIdentifier !== '') {
            $qb
                ->andWhere('LOWER(a.adminIdentifier) LIKE :adminIdentifier')
                ->setParameter('adminIdentifier', '%' . mb_strtolower($adminIdentifier) . '%');
        }

        if ($from instanceof DateTimeImmutable) {
            $qb
                ->andWhere('a.createdAt >= :fromDate')
                ->setParameter('fromDate', $from);
        }

        if ($to instanceof DateTimeImmutable) {
            $qb
                ->andWhere('a.createdAt <= :toDate')
                ->setParameter('toDate', $to);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function countActionSince(string $action, DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere(self::WHERE_ACTION)
            ->andWhere('a.createdAt >= :since')
            ->setParameter('action', $action)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<string>
     */
    public function findDistinctActions(int $limit = 100): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('DISTINCT a.action AS actionName')
            ->orderBy('actionName', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getScalarResult();

        $actions = [];
        foreach ($rows as $row) {
            $value = $row['actionName'] ?? null;
            if (is_string($value) && $value !== '') {
                $actions[] = $value;
            }
        }

        return $actions;
    }
}
