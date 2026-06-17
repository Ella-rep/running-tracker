<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\WeeklySummaryMailer;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Handles bulk plan maintenance operations.
 */
final class PlanMaintenanceApiController extends AbstractController
{
    public function __construct(
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
            'message' => 'ROLE_ADMIN ajouté avec succès.',
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
                    'message' => 'Cet utilisateur est déjà admin.',
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
                    'message' => 'ROLE_ADMIN ajouté avec succès.',
                    'username' => $user->getUserIdentifier(),
                    'roles' => $user->getRoles(),
                ];
            }
        }

        return $this->json($response, $status);
    }

    /**
     * Sends the actionable weekly recap email to subscribers on demand,
     * without waiting for the end-of-week cron. Route in config/routes/admin.yaml.
     */
    public function sendWeeklySummaryNow(WeeklySummaryMailer $weeklySummaryMailer): JsonResponse
    {
        try {
            $admin = $this->requireAdminUser();

            $result = $weeklySummaryMailer->sendAll();

            $this->logger->warning('Admin maintenance: weekly recap sent on demand.', [
                'admin' => $admin->getUserIdentifier(),
            ] + $result);

            return $this->json([
                'message' => sprintf(
                    'Récap envoyé : %d mail(s), %d ignoré(s), %d échec(s).',
                    $result['sent'],
                    $result['skipped'],
                    $result['failed']
                ),
            ] + $result);
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
     * Debug helper: returns the DEFAULT_URI environment variable as seen by the app
     * (router default_uri, used to build absolute links in the weekly recap email).
     * Admin only. Route in config/routes/admin.yaml.
     */
    public function showDefaultUri(): JsonResponse
    {
        try {
            $this->requireAdminUser();
        } catch (AccessDeniedHttpException $e) {
            return $this->json([
                'message' => $e->getMessage(),
                'error' => 'access_denied',
            ], 403);
        }

        $raw = $_ENV['DEFAULT_URI'] ?? $_SERVER['DEFAULT_URI'] ?? null;

        return $this->json([
            'defaultUri' => $raw,
            'isSet' => $raw !== null && $raw !== '',
        ]);
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


