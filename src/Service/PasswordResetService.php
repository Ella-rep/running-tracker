<?php

namespace App\Service;

use App\Exception\PasswordResetEmailException;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Handles business rules for password reset request and confirmation flows.
 */
final class PasswordResetService
{
    private const RESET_TTL_MINUTES = 30;

    /**
     * @param UserRepository $users User repository.
     * @param EntityManagerInterface $entityManager Entity manager used for persistence.
     * @param MailerInterface $mailer Mailer used to send reset links.
     * @param LoggerInterface $logger Logger for operational failures.
     * @param UserPasswordHasherInterface $passwordHasher Password hasher for reset confirmation.
     */
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $entityManager,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    /**
        * Creates and sends a password reset token when the account exists.
        *
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function requestReset(string $rawPayload, string $schemeAndHost): array
    {
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
            $data = json_decode($rawPayload, true, 512, \JSON_THROW_ON_ERROR);
            $email = strtolower(trim((string) ($data['email'] ?? '')));

            if ($email === '') {
                $status = 400;
                $payload = [
                    'code' => 'missing_reset_fields',
                    'message' => 'Email requis.',
                ];
            } else {
                $user = $this->users->findOneBy(['email' => $email]);
                if ($user !== null && $user->getEmail() !== null) {
                    $accountMatched = true;
                    // Token is stored hashed in DB; only plain value is sent to user.
                    $plainToken = bin2hex(random_bytes(32));
                    $expiresAt = new \DateTimeImmutable('+' . self::RESET_TTL_MINUTES . ' minutes');

                    $user
                        ->setResetPasswordTokenHash(hash('sha256', $plainToken))
                        ->setResetPasswordExpiresAt($expiresAt);
                    $this->entityManager->flush();

                    $resetUrl = rtrim($schemeAndHost, '/') . '/login?resetToken=' . urlencode($plainToken);
                    $mailAttempted = true;
                    $this->sendResetEmail($email, $resetUrl);
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
            $this->logger->error('Password reset email transport error.', [
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

        return [
            'status' => $status,
            'payload' => $payload,
        ];
    }

    /**
        * Validates reset token and updates the account password.
        *
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function confirmReset(string $rawPayload): array
    {
        $status = 200;
        $payload = [
            'message' => 'Mot de passe réinitialisé. Tu peux maintenant te connecter.',
        ];

        try {
            $data = json_decode($rawPayload, true, 512, \JSON_THROW_ON_ERROR);
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
                $user = $this->users->findOneBy(['resetPasswordTokenHash' => hash('sha256', $token)]);
                if ($user === null || $user->getResetPasswordExpiresAt() === null || $user->getResetPasswordExpiresAt() < new \DateTimeImmutable()) {
                    $status = 400;
                    $payload = [
                        'code' => 'invalid_or_expired_token',
                        'message' => 'Le lien de réinitialisation est invalide ou expiré.',
                    ];
                } else {
                    $user
                        ->setPassword($this->passwordHasher->hashPassword($user, $plainPassword))
                        ->setResetPasswordTokenHash(null)
                        ->setResetPasswordExpiresAt(null);
                    $user->eraseCredentials();
                    $this->entityManager->flush();
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

        return [
            'status' => $status,
            'payload' => $payload,
        ];
    }

    private function sendResetEmail(string $email, string $resetUrl): void
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
            $this->mailer->send($emailMessage);
        } catch (TransportExceptionInterface $e) {
            throw new PasswordResetEmailException('Email de réinitialisation non envoyé.', 0, $e);
        }
    }
}
