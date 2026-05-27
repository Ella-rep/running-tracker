<?php

namespace App\Repository;

use App\Entity\AdminAuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use DateTimeImmutable;

class AdminAuditLogRepository extends ServiceEntityRepository
{
    private const COUNT_EXPR = 'COUNT(a.id)';
    private const WHERE_ACTION = 'a.action = :action';
    private const ACTION_CREATE = 'user_create';
    private const ACTION_RESET_PASSWORD = 'user_reset_password';
    private const ACTION_DELETE = 'user_delete';

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
            ->select(self::COUNT_EXPR);

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
            ->select(self::COUNT_EXPR)
            ->andWhere(self::WHERE_ACTION)
            ->andWhere('a.createdAt >= :since')
            ->setParameter('action', $action)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countSince(DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select(self::COUNT_EXPR)
            ->andWhere('a.createdAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countDistinctAdminsSince(DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(DISTINCT a.adminIdentifier)')
            ->andWhere('a.createdAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return array{action: string, count: int}|null
     */
    public function findTopActionSince(DateTimeImmutable $since): ?array
    {
        $row = $this->createQueryBuilder('a')
            ->select('a.action AS actionName, COUNT(a.id) AS actionCount')
            ->andWhere('a.createdAt >= :since')
            ->setParameter('since', $since)
            ->groupBy('a.action')
            ->orderBy('actionCount', 'DESC')
            ->addOrderBy('actionName', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!is_array($row) || !is_string($row['actionName'] ?? null)) {
            return null;
        }

        return [
            'action' => $row['actionName'],
            'count' => (int) ($row['actionCount'] ?? 0),
        ];
    }

    /**
     * @return array{create: int, reset_password: int, delete: int}
     */
    public function getUserActionSummarySince(DateTimeImmutable $since): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('a.action AS actionName, COUNT(a.id) AS actionCount')
            ->andWhere('a.createdAt >= :since')
            ->andWhere('a.action IN (:actions)')
            ->setParameter('since', $since)
            ->setParameter('actions', [self::ACTION_CREATE, self::ACTION_RESET_PASSWORD, self::ACTION_DELETE])
            ->groupBy('a.action')
            ->getQuery()
            ->getScalarResult();

        $summary = [
            'create' => 0,
            'reset_password' => 0,
            'delete' => 0,
        ];

        foreach ($rows as $row) {
            $action = $row['actionName'] ?? null;
            $count = (int) ($row['actionCount'] ?? 0);
            if ($action === self::ACTION_CREATE) {
                $summary['create'] = $count;
            } elseif ($action === self::ACTION_RESET_PASSWORD) {
                $summary['reset_password'] = $count;
            } elseif ($action === self::ACTION_DELETE) {
                $summary['delete'] = $count;
            }
        }

        return $summary;
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
