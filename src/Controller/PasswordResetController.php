<?php

namespace App\Controller;

use App\Exception\PasswordResetEmailException;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class PasswordResetController extends AbstractController
{
    private const RESET_TTL_MINUTES = 30;

    #[Route('/api/auth/reset-password/request', name: 'api_auth_reset_password_request', methods: ['POST'])]
    public function requestReset(
        Request $request,
        UserRepository $users,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $status = 200;
        $payload = [
            'message' => 'Si les informations sont correctes, un email de réinitialisation a été envoyé.',
        ];

        try {
            $data = json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR);
            $username = trim((string) ($data['username'] ?? ''));
            $email = strtolower(trim((string) ($data['email'] ?? '')));

            if ($username === '' || $email === '') {
                $status = 400;
                $payload = [
                    'code' => 'missing_reset_fields',
                    'message' => 'Login et email requis.',
                ];
            } else {
                $user = $users->findOneBy(['username' => $username]);
                if ($user !== null && $user->getEmail() !== null && $user->getEmail() === $email) {
                    $plainToken = bin2hex(random_bytes(32));
                    $expiresAt = new \DateTimeImmutable('+' . self::RESET_TTL_MINUTES . ' minutes');

                    $user
                        ->setResetPasswordTokenHash(hash('sha256', $plainToken))
                        ->setResetPasswordExpiresAt($expiresAt);
                    $entityManager->flush();

                    $resetUrl = rtrim($request->getSchemeAndHttpHost(), '/') . '/login?resetToken=' . urlencode($plainToken);
                    $this->sendResetEmail($email, $resetUrl);
                }
            }
        } catch (\JsonException) {
            $status = 400;
            $payload = [
                'code' => 'invalid_payload',
                'message' => 'Requête de réinitialisation invalide.',
            ];
        } catch (\Throwable) {
            $status = 500;
            $payload = [
                'code' => 'internal_error',
                'message' => 'Erreur interne pendant l\'envoi de l\'email de réinitialisation.',
            ];
        }

        return $this->json($payload, $status);
    }

    #[Route('/api/auth/reset-password/confirm', name: 'api_auth_reset_password_confirm', methods: ['POST'])]
    public function confirmReset(
        Request $request,
        UserRepository $users,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $status = 200;
        $payload = [
            'message' => 'Mot de passe réinitialisé. Tu peux maintenant te connecter.',
        ];

        try {
            $data = json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR);
            $token = trim((string) ($data['token'] ?? ''));
            $plainPassword = (string) ($data['plainPassword'] ?? '');

            if ($token === '' || $plainPassword === '') {
                $status = 400;
                $payload = [
                    'code' => 'missing_reset_fields',
                    'message' => 'Token et nouveau mot de passe requis.',
                ];
            } elseif (strlen($plainPassword) < 6) {
                $status = 400;
                $payload = [
                    'code' => 'password_too_short',
                    'message' => 'Le nouveau mot de passe doit contenir au moins 6 caractères.',
                ];
            } else {
                $user = $users->findOneBy(['resetPasswordTokenHash' => hash('sha256', $token)]);
                if ($user === null || $user->getResetPasswordExpiresAt() === null || $user->getResetPasswordExpiresAt() < new \DateTimeImmutable()) {
                    $status = 400;
                    $payload = [
                    'code' => 'invalid_or_expired_token',
                    'message' => 'Le lien de réinitialisation est invalide ou expiré.',
                    ];
                } else {
                    $user
                        ->setPassword($passwordHasher->hashPassword($user, $plainPassword))
                        ->setResetPasswordTokenHash(null)
                        ->setResetPasswordExpiresAt(null);
                    $user->eraseCredentials();
                    $entityManager->flush();
                }
            }
        } catch (\JsonException) {
            $status = 400;
            $payload = [
                'code' => 'invalid_payload',
                'message' => 'Requête de réinitialisation invalide.',
            ];
        } catch (\Throwable) {
            $status = 500;
            $payload = [
                'code' => 'internal_error',
                'message' => 'Erreur interne pendant la réinitialisation du mot de passe.',
            ];
        }

        return $this->json($payload, $status);
    }

    private function sendResetEmail(string $email, string $resetUrl): void
    {
        $subject = 'Réinitialisation de ton mot de passe';
        $message = "Tu as demandé une réinitialisation de mot de passe.\n\n"
            . "Clique sur ce lien (valable " . self::RESET_TTL_MINUTES . " minutes) :\n"
            . $resetUrl
            . "\n\nSi tu n'es pas à l'origine de cette demande, ignore cet email.";
        $headers = implode("\r\n", [
            'From: Run Tracker <no-reply@runtracker.local>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
        ]);

        if (!mail($email, $subject, $message, $headers)) {
            throw new PasswordResetEmailException('Email de réinitialisation non envoyé.');
        }
    }
}

