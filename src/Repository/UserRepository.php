<?php
namespace App\Repository;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use DateTimeImmutable;
class UserRepository extends ServiceEntityRepository {
    public function __construct(ManagerRegistry $r) { parent::__construct($r, User::class); }

<<<<<<< HEAD
    public function countCreatedSince(DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.createdAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
=======
    public function findOneByEmailInsensitive(string $email): ?User
    {
        $normalized = strtolower(trim($email));
        if ($normalized == '') {
            return null;
        }

        return $this->createQueryBuilder('u')
            ->andWhere('LOWER(u.email) = :email')
            ->setParameter('email', $normalized)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
>>>>>>> origin/main
    }
}
