<?php

namespace App\Controller;

use App\Exception\PasswordResetEmailException;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Email;
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
        MailerInterface $mailer,
        LoggerInterface $logger,
    ): JsonResponse {
        $isDebug = filter_var((string) ($_ENV['APP_DEBUG'] ?? '0'), \FILTER_VALIDATE_BOOL);
        $accountMatched = false;
        $mailAttempted = false;
        $mailSent = false;
        $mailError = null;

        $status = 200;
        $payload = [
            'message' => 'Si les informations sont correctes, un email de réinitialisation a été envoyé.',
        ];

        try {
            $data = json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR);
            $email = strtolower(trim((string) ($data['email'] ?? '')));

            if ($email === '') {
                $status = 400;
                $payload = [
                    'code' => 'missing_reset_fields',
                    'message' => 'Email requis.',
                ];
            } else {
                $user = $users->findOneBy(['email' => $email]);
                if ($user !== null && $user->getEmail() !== null) {
                    $accountMatched = true;
                    $plainToken = bin2hex(random_bytes(32));
                    $expiresAt = new \DateTimeImmutable('+' . self::RESET_TTL_MINUTES . ' minutes');

                    $user
                        ->setResetPasswordTokenHash(hash('sha256', $plainToken))
                        ->setResetPasswordExpiresAt($expiresAt);
                    $entityManager->flush();

                    $resetUrl = rtrim($request->getSchemeAndHttpHost(), '/') . '/login?resetToken=' . urlencode($plainToken);
                    $mailAttempted = true;
                    $this->sendResetEmail($mailer, $email, $resetUrl);
                    $mailSent = true;
                }
            }
        } catch (\JsonException) {
            $status = 400;
            $payload = [
                'code' => 'invalid_payload',
                'message' => 'Requête de réinitialisation invalide.',
            ];
        } catch (PasswordResetEmailException $e) {
            $status = 500;
            $payload = [
                'code' => 'internal_error',
                'message' => 'Erreur interne pendant l\'envoi de l\'email de réinitialisation.',
            ];
            $mailError = $e->getPrevious() !== null ? $e->getPrevious()->getMessage() : $e->getMessage();
            $logger->error('Password reset email transport error.', [
                'exception_class' => $e::class,
                'transport_error' => $mailError,
            ]);
        } catch (\Throwable) {
            $status = 500;
            $payload = [
                'code' => 'internal_error',
                'message' => 'Erreur interne pendant l\'envoi de l\'email de réinitialisation.',
            ];
        }

        if ($isDebug) {
            $mailerDsn = (string) ($_ENV['MAILER_DSN'] ?? '');
            $maskedMailerDsn = preg_replace('/:\/\/[^:@\/]+:[^@\/]+@/', '://***:***@', $mailerDsn) ?: $mailerDsn;

            $payload['_debug'] = [
                'accountMatched' => $accountMatched,
                'mailAttempted' => $mailAttempted,
                'mailSent' => $mailSent,
                'appEnv' => (string) ($_ENV['APP_ENV'] ?? ''),
                'mailerFrom' => (string) ($_ENV['MAILER_FROM'] ?? ''),
                'mailerDsn' => $maskedMailerDsn,
                'mailError' => $mailError,
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

    private function sendResetEmail(MailerInterface $mailer, string $email, string $resetUrl): void
    {
        $subject = 'Réinitialisation de ton mot de passe';
        $message = "Tu as demandé une réinitialisation de mot de passe.\n\n"
            . "Clique sur ce lien (valable " . self::RESET_TTL_MINUTES . " minutes) :\n"
            . $resetUrl
            . "\n\nSi tu n'es pas à l'origine de cette demande, ignore cet email.";

        $from = $_ENV['MAILER_FROM'] ?? 'no-reply@runtracker.app';
        $emailMessage = (new Email())
            ->from($from)
            ->to($email)
            ->subject($subject)
            ->text($message);

        try {
            $mailer->send($emailMessage);
        } catch (TransportExceptionInterface $e) {
            throw new PasswordResetEmailException('Email de réinitialisation non envoyé.', 0, $e);
        }
    }
}

