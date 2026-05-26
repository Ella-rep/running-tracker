<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lightweight factory for integration tests.
 */
final class UserFactory
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    /**
     * Creates and persists a test user.
     */
    public function createOne(
        ?string $username = null,
        ?string $email = null,
        string $plainPassword = 'TestPass123!',
        array $roles = ['ROLE_USER'],
    ): User {
        $suffix = bin2hex(random_bytes(4));
        $resolvedUsername = $username ?? ('user_' . $suffix);
        $resolvedEmail = $email ?? ('user_' . $suffix . '@example.test');

        $user = (new User())
            ->setUsername($resolvedUsername)
            ->setEmail($resolvedEmail)
            ->setRoles($roles)
            ->setPassword('');

        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
