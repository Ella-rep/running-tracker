<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\GoogleOAuthErrorReportService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Handles bulk plan maintenance operations.
 */
final class PlanMaintenanceApiController extends AbstractController
{
    public function __construct(
        private readonly GoogleOAuthErrorReportService $googleOAuthErrorReportService,
        private readonly MailerInterface $mailer,
        #[Autowire('%env(string:CONTACT_EMAIL_TO)%')] private readonly string $contactEmailTo,
        #[Autowire('%env(string:MAILER_FROM)%')] private readonly string $mailerFrom,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Grants ROLE_ADMIN to a user from a maintenance action.
     * Route is defined in config/routes/admin.yaml.
     */
    public function grantAdminRoleForTest(
        Request $request,
        UserRepository $userRepository,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $status = 200;
        $identifier = '';
        $user = null;
        $response = [
            'message' => 'ROLE_ADMIN ajoute avec succes.',
        ];

        try {
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $status = 400;
            $response = [
                'message' => 'Payload JSON invalide.',
                'error' => 'invalid_json',
            ];
            $payload = [];
        }

        if ($status === 200) {
            $identifier = trim((string) ($payload['identifier'] ?? ''));
            if ($identifier == '') {
                $status = 400;
                $response = [
                    'message' => 'Le champ identifier (pseudo ou email) est requis.',
                    'error' => 'missing_identifier',
                ];
            }
        }

        if ($status === 200) {
            $user = $userRepository->findOneBy(['username' => $identifier]);
            if (!$user instanceof User) {
                $user = $userRepository->findOneBy(['email' => $identifier]);
            }

            if (!$user instanceof User) {
                $status = 404;
                $response = [
                    'message' => 'Utilisateur introuvable.',
                    'error' => 'user_not_found',
                ];
            }
        }

        if ($status === 200) {
            $roles = $user->getRoles();
            if (in_array('ROLE_ADMIN', $roles, true)) {
                $response = [
                    'message' => 'Cet utilisateur est deja admin.',
                    'username' => $user->getUserIdentifier(),
                    'roles' => $roles,
                ];
            } else {
                $roles[] = 'ROLE_ADMIN';
                $user->setRoles(array_values(array_unique($roles)));
                $entityManager->flush();

                $this->logger->warning('Test maintenance: ROLE_ADMIN granted from login page.', [
                    'username' => $user->getUserIdentifier(),
                    'source' => 'login_test_maintenance',
                ]);

                $response = [
                    'message' => 'ROLE_ADMIN ajoute avec succes.',
                    'username' => $user->getUserIdentifier(),
                    'roles' => $user->getRoles(),
                ];
            }
        }

        return $this->json($response, $status);
    }

    /**
     * Sends a Gmail/OAuth operational error report to the internal contact mailbox.
     * Route is defined in config/routes/admin.yaml to avoid caching issues.
     */
    public function sendGoogleOAuthErrorReport(): JsonResponse
    {
        try {
            $admin = $this->requireAdminUser();

            $report = $this->googleOAuthErrorReportService->collectRecentErrors();
            $body = $this->googleOAuthErrorReportService->buildReportBody($report);

            $email = (new Email())
                ->from($this->mailerFrom)
                ->to($this->contactEmailTo)
                ->subject(sprintf('[Admin] Rapport erreurs Gmail OAuth (%d erreurs)', (int) $report['count']))
                ->text($body);

            $this->mailer->send($email);

            $this->logger->error('Admin maintenance: Google OAuth error report sent.', [
                'admin' => $admin->getUserIdentifier(),
                'errors_count' => $report['count'],
                'window_hours' => $report['window_hours'],
                'target' => $this->contactEmailTo,
            ]);

            return $this->json([
                'message' => 'Rapport erreurs Gmail envoye.',
                'errors' => $report['count'],
                'window_hours' => $report['window_hours'],
                'codes' => $report['codes'],
            ]);
        } catch (AccessDeniedHttpException $e) {
            return $this->json([
                'message' => $e->getMessage(),
                'error' => 'access_denied',
            ], 403);
        } catch (\Throwable $e) {
            return $this->json([
                'message' => $e->getMessage(),
                'error' => 'internal_error',
            ], 500);
        }
    }

    /**
     * Returns the current authenticated user.
     */
    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Authentication required.');
        }

        return $user;
    }

    /**
     * Ensures the current user is authenticated and admin.
     */
    private function requireAdminUser(): User
    {
        $user = $this->requireUser();
        if (!$this->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedHttpException('Admin role required.');
        }

        return $user;
    }

}


