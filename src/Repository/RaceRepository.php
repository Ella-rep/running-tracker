<?php
namespace App\Repository;
use App\Entity\Race;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
class RaceRepository extends ServiceEntityRepository {
    public function __construct(ManagerRegistry $r) { parent::__construct($r, Race::class); }

    public function countDistinctUsersWithRaces(): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(DISTINCT IDENTITY(r.user))')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findNextRace(\App\Entity\User $user, string $todayYmd): ?Race
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.user = :user')
            ->andWhere('r.date >= :today')
            ->andWhere('r.dnfStatus IS NULL')
            ->setParameter('user', $user)
            ->setParameter('today', $todayYmd)
            ->orderBy('r.date', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
