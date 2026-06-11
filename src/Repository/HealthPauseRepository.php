<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\HealthPause;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class HealthPauseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HealthPause::class);
    }

    public function findActiveByUser(User $user): ?HealthPause
    {
        return $this->createQueryBuilder('hp')
            ->andWhere('hp.user = :user')
            ->andWhere('hp.resumedAt IS NULL')
            ->setParameter('user', $user)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
