<?php

namespace App\Service;

use App\Repository\UserRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Handles login business logic and token generation.
 */
final class AuthLoginService
{
    /**
     * @param UserRepository $users User repository.
     * @param UserPasswordHasherInterface $passwordHasher Password hasher for credential verification.
     * @param JWTTokenManagerInterface $jwtManager JWT token generator.
     * @param LoggerInterface $logger Logger for operational authentication errors.
     */
    public function __construct(
        private UserRepository $users,
        private UserPasswordHasherInterface $passwordHasher,
        private JWTTokenManagerInterface $jwtManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
        * Authenticates credentials and returns either a JWT payload or a normalized API error.
        *
     * @return array{status:int,payload:array<string,string>}
     */
    public function authenticate(string $email, string $password): array
    {
        $status = 200;
        $payload = [];

        if ($email === '' || $password === '') {
            $status = 400;
            $payload = [
                'code' => 'missing_credentials',
                'message' => 'Email et mot de passe requis.',
            ];
        } else {
            $user = $this->users->findOneBy(['email' => $email]);
            if ($user === null || !$this->passwordHasher->isPasswordValid($user, $password)) {
                $status = 401;
                $payload = [
                    'code' => 'invalid_credentials',
                    'message' => 'Identifiants invalides.',
                ];
            } else {
                try {
                    $payload = [
                        'token' => $this->jwtManager->create($user),
                    ];
                } catch (\Throwable $e) {
                    $this->logger->error('JWT generation failed during login.', [
                        'user_id' => $user->getId(),
                        'email' => $email,
                        'exception_class' => $e::class,
                        'exception_message' => $e->getMessage(),
                    ]);

                    $status = 500;
                    $payload = [
                        'code' => 'jwt_generation_failed',
                        'message' => 'Configuration JWT invalide sur le serveur (cles/passphrase).',
                    ];
                }
            }
        }

        return [
            'status' => $status,
            'payload' => $payload,
        ];
    }
}
