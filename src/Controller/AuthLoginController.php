<?php

namespace App\Controller;

use App\Repository\UserRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class AuthLoginController extends AbstractController
{
    #[Route('/api/auth/login', name: 'api_auth_login', methods: ['POST'])]
    public function __invoke(
        Request $request,
        UserRepository $users,
        UserPasswordHasherInterface $passwordHasher,
        JWTTokenManagerInterface $jwtManager,
        LoggerInterface $logger,
    ): JsonResponse {
        $status = 200;
        $payload = [];

        try {
            $data = json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR);
            $email = strtolower(trim((string) ($data['email'] ?? '')));
            $password = (string) ($data['password'] ?? '');

            if ($email === '' || $password === '') {
                $status = 400;
                $payload = [
                    'code' => 'missing_credentials',
                    'message' => 'Email et mot de passe requis.',
                ];
            } else {
                $user = $users->findOneBy(['email' => $email]);
                if ($user === null) {
                    $status = 401;
                    $payload = [
                        'code' => 'unknown_email',
                        'message' => 'Cette adresse email n\'existe pas.',
                    ];
                } elseif (!$passwordHasher->isPasswordValid($user, $password)) {
                    $status = 401;
                    $payload = [
                        'code' => 'invalid_password',
                        'message' => 'Mot de passe incorrect.',
                    ];
                } else {
                    try {
                        $payload = [
                            'token' => $jwtManager->create($user),
                        ];
                    } catch (\Throwable $e) {
                        $logger->error('JWT generation failed during login.', [
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
        } catch (\JsonException) {
            $status = 400;
            $payload = [
                'code' => 'invalid_payload',
                'message' => 'Requete de connexion invalide.',
            ];
        } catch (\Throwable $e) {
            $logger->error('Unhandled error during login.', [
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);

            $status = 500;
            $payload = [
                'code' => 'internal_error',
                'message' => 'Erreur interne pendant la connexion.',
            ];
        }

        return $this->json($payload, $status);
    }
}
