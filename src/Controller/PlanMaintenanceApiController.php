<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Plan;
use App\Entity\PlanDetails;
use App\Entity\User;
use App\Repository\PlanDetailsRepository;
use App\Repository\PlanRepository;
use App\Service\PlanSessionReplaceService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Handles bulk plan maintenance operations.
 */
final class PlanMaintenanceApiController extends AbstractController
{
    public function __construct(
        private readonly PlanRepository $planRepository,
        private readonly PlanDetailsRepository $planDetailsRepository,
        private readonly PlanSessionReplaceService $replaceService,
    ) {
    }

    /**
     * Recomputes compact training week indices for every plan in the system.
     * Route is defined in config/routes/admin.yaml to avoid caching issues.
     */
    // #[Route('/api/admin/plans/recompute-training-weeks', name: 'api_admin_plans_recompute_training_weeks', methods: ['POST'])]
    public function recomputeTrainingWeeksForAllUsers(): JsonResponse
    {
        try {
            $user = $this->requireAdminUser();

            /** @var array<int, Plan> $plans */
            $plans = $this->planRepository->findBy([], ['id' => 'ASC']);

            $updatedPlans = 0;
            $updatedSessions = 0;
            $usersTouched = [];

            foreach ($plans as $plan) {
                $owner = $plan->getUser();
                $ownerId = $owner->getId();
                if ($ownerId !== null) {
                    $usersTouched[$ownerId] = true;
                }

                $details = $this->planDetailsRepository->findBy(
                    ['plan' => $plan, 'user' => $owner],
                    ['position' => 'ASC']
                );

                $sessions = $this->mapDetailsToSessions($details);
                $doneMap = $this->mapDoneByIndex($details);

                $this->replaceService->replaceForPlan($plan, $owner, $sessions, $doneMap);

                $updatedPlans++;
                $updatedSessions += count($sessions);
            }

            return $this->json([
                'message' => 'Training weeks recomputed for all users.',
                'users' => count($usersTouched),
                'plans' => $updatedPlans,
                'sessions' => $updatedSessions,
            ]);
        } catch (AccessDeniedHttpException $e) {
            return $this->json([
                'message' => $e->getMessage(),
                'error' => 'access_denied',
            ], 403);
        } catch (\Exception $e) {
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

    /**
     * @param array<int, PlanDetails> $details
     * @return array<int, array<string, mixed>>
     */
    private function mapDetailsToSessions(array $details): array
    {
        $sessions = [];

        foreach ($details as $row) {
            $sessions[] = [
                'sem' => $row->getSem(),
                'date' => $row->getSessionDate()?->format('Y-m-d'),
                'format' => $row->getFormat(),
                'sessionType' => $row->getSessionType(),
                'pe' => $row->getPe(),
                'totalMin' => $row->getTotalMin(),
                'isOptional' => $row->isOptional(),
            ];
        }

        return $sessions;
    }

    /**
     * @param array<int, PlanDetails> $details
     * @return array<int, bool>
     */
    private function mapDoneByIndex(array $details): array
    {
        $doneMap = [];

        foreach ($details as $row) {
            $doneMap[] = $row->isDone();
        }

        return $doneMap;
    }
}


