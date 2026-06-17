<?php

namespace App\Repository;

use App\Entity\User;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function countCreatedSince(DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.createdAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findOneByEmailInsensitive(string $email): ?User
    {
        $normalized = strtolower(trim($email));
        if ($normalized === '') {
            return null;
        }

        return $this->createQueryBuilder('u')
            ->andWhere('LOWER(u.email) = :email')
            ->setParameter('email', $normalized)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Users opted in to the weekly email digest and having a usable address.
     * @return array<int, User>
     */
    public function findWeeklyEmailSubscribers(): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.emailHebdo = true')
            ->andWhere('u.email IS NOT NULL')
            ->getQuery()
            ->getResult();
    }
}
