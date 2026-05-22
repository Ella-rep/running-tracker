<?php

namespace App\Repository;

use App\Entity\AdminAnnouncement;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AdminAnnouncementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdminAnnouncement::class);
    }

    public function findCurrent(?DateTimeImmutable $now = null): ?AdminAnnouncement
    {
        $now ??= new DateTimeImmutable();

        return $this->createQueryBuilder('a')
            ->andWhere('a.isActive = true')
            ->andWhere('a.startsAt IS NULL OR a.startsAt <= :now')
            ->andWhere('a.endsAt IS NULL OR a.endsAt >= :now')
            ->setParameter('now', $now)
            ->orderBy('a.updatedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
