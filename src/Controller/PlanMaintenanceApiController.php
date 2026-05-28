<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\GoogleOAuthErrorReportService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

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


