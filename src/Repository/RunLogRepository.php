<?php
namespace App\Repository;
use App\Entity\Plan;
use App\Entity\PlanDetails;
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

    /**
     * Returns the run logs relevant to a plan, ordered by date ASC.
     *
     * Combines two sources so the evolution recap is not empty when sessions are
     * validated without an explicitly linked run:
     *  1. runs explicitly linked to the plan via a planned session;
     *  2. the plan owner's runs falling inside the plan's scheduled date window
     *     (min..max sessionDate of its PlanDetails).
     * Results are de-duplicated by id.
     *
     * @return array<int, RunLog>
     */
    public function findByPlan(Plan $plan): array
    {
        $byId = [];

        $linked = $this->createQueryBuilder('r')
            ->join('r.plannedSession', 'd')
            ->andWhere('d.plan = :plan')
            ->setParameter('plan', $plan)
            ->getQuery()
            ->getResult();
        foreach ($linked as $log) {
            $byId[(int) $log->getId()] = $log;
        }

        $window = $this->getEntityManager()->createQueryBuilder()
            ->select('MIN(d.sessionDate) AS minDate', 'MAX(d.sessionDate) AS maxDate')
            ->from(PlanDetails::class, 'd')
            ->andWhere('d.plan = :plan')
            ->andWhere('d.sessionDate IS NOT NULL')
            ->setParameter('plan', $plan)
            ->getQuery()
            ->getOneOrNullResult();

        if ($window !== null && $window['minDate'] !== null && $window['maxDate'] !== null) {
            $from = $window['minDate'] instanceof \DateTimeInterface
                ? $window['minDate']->format('Y-m-d')
                : substr((string) $window['minDate'], 0, 10);
            $to = $window['maxDate'] instanceof \DateTimeInterface
                ? $window['maxDate']->format('Y-m-d')
                : substr((string) $window['maxDate'], 0, 10);

            foreach ($this->findByUserAndDateRange($plan->getUser(), $from, $to) as $log) {
                $byId[(int) $log->getId()] = $log;
            }
        }

        $logs = array_values($byId);
        usort($logs, static fn (RunLog $a, RunLog $b): int => strcmp($a->getDate(), $b->getDate()));

        return $logs;
    }

    /**
     * Returns user run logs whose date (YYYY-MM-DD) is within [from, to] inclusive, ordered by date ASC.
     * @return array<int, RunLog>
     */
    public function findByUserAndDateRange(User $user, string $fromYmd, string $toYmd): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.user = :user')
            ->andWhere('r.date >= :from')
            ->andWhere('r.date <= :to')
            ->setParameter('user', $user)
            ->setParameter('from', $fromYmd)
            ->setParameter('to', $toYmd)
            ->orderBy('r.date', 'ASC')
            ->getQuery()
            ->getResult();
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
