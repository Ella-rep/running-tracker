<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Plan;
use App\Entity\PlanDetails;
use App\Entity\User;
use App\Entity\PlanProgress;
use App\Repository\PlanDetailsRepository;
use App\Repository\PlanProgressRepository;
use App\Repository\PlanRepository;
use App\Service\PlanSessionReplaceService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Handles single-session operations while delegating consistency rules to backend.
 */
final class PlanSessionApiController extends AbstractController
{
    public function __construct(
        private readonly PlanRepository $planRepository,
        private readonly PlanDetailsRepository $planDetailsRepository,
        private readonly PlanProgressRepository $planProgressRepository,
        private readonly PlanSessionReplaceService $replaceService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Creates one session in the target plan.
     */
    #[Route('/api/plans/{planId<\d+>}/sessions', name: 'api_plans_session_create', methods: ['POST'])]
    public function createSession(int $planId, Request $request): JsonResponse
    {
        $user = $this->requireUser();
        $plan = $this->findOwnedPlan($planId, $user);

        $payload = $this->decodeJsonPayload($request);
        $newSession = $this->normalizeSessionPayload($payload, false);

        $details = $this->findPlanDetailsRows($plan, $user);
        $sessions = $this->mapDetailsToSessions($details);
        $doneMap = $this->mapDoneByIndex($details);

        $sessions[] = $newSession;
        $doneMap[] = false;

        $this->replaceService->replaceForPlan($plan, $user, $sessions, $doneMap);

        return $this->json(['message' => 'Session created.'], 201);
    }

    /**
     * Updates one session in the target plan.
     */
    #[Route('/api/plans/{planId<\d+>}/sessions/{detailId<\d+>}', name: 'api_plans_session_update', methods: ['PATCH'])]
    public function updateSession(int $planId, int $detailId, Request $request): JsonResponse
    {
        $user = $this->requireUser();
        $plan = $this->findOwnedPlan($planId, $user);

        $details = $this->findPlanDetailsRows($plan, $user);
        [$sessionIndex, $targetRow] = $this->findDetailRowById($details, $detailId);

        $payload = $this->decodeJsonPayload($request);
        $patch = $this->normalizeSessionPayload($payload, true);

        $sessions = $this->mapDetailsToSessions($details);
        $doneMap = $this->mapDoneByIndex($details);

        $sessions[$sessionIndex] = array_merge($sessions[$sessionIndex], $patch);
        $doneMap[$sessionIndex] = array_key_exists('done', $patch)
            ? (bool) $patch['done']
            : $targetRow->isDone();

        $this->replaceService->replaceForPlan($plan, $user, $sessions, $doneMap);

        if (array_key_exists('done', $patch)) {
            $planKey = (string) ($plan->getId() ?? '');
            if ($planKey !== '') {
                $done = (bool) $doneMap[$sessionIndex];
                $existing = $this->planProgressRepository->findOneBy([
                    'user' => $user,
                    'planKey' => $planKey,
                    'sessionIndex' => $sessionIndex,
                ]);

                if ($existing instanceof PlanProgress) {
                    $existing->setDone($done);
                    $this->entityManager->flush();
                } elseif ($done) {
                    $progress = (new PlanProgress())
                        ->setUser($user)
                        ->setPlanKey($planKey)
                        ->setSessionIndex($sessionIndex)
                        ->setDone(true);
                    $this->entityManager->persist($progress);
                    $this->entityManager->flush();
                }
            }
        }

        return $this->json(['message' => 'Session updated.']);
    }

    /**
     * Toggles the isCancelled flag on a session without going through replaceForPlan.
     */
    #[Route('/api/plans/{planId<\d+>}/sessions/{detailId<\d+>}/cancel', name: 'api_plans_session_cancel', methods: ['PATCH'])]
    public function toggleCancelSession(int $planId, int $detailId, Request $request): JsonResponse
    {
        $user = $this->requireUser();
        $plan = $this->findOwnedPlan($planId, $user);

        $details = $this->findPlanDetailsRows($plan, $user);
        [, $row] = $this->findDetailRowById($details, $detailId);

        $payload = $this->decodeJsonPayload($request);
        $isCancelled = isset($payload['isCancelled']) ? (bool) $payload['isCancelled'] : !$row->isCancelled();

        $row->setIsCancelled($isCancelled);
        $this->entityManager->flush();

        return $this->json(['isCancelled' => $isCancelled]);
    }

    /**
     * Deletes one session from the target plan.
     */
    #[Route('/api/plans/{planId<\d+>}/sessions/{detailId<\d+>}', name: 'api_plans_session_delete', methods: ['DELETE'])]
    public function deleteSession(int $planId, int $detailId): JsonResponse
    {
        $user = $this->requireUser();
        $plan = $this->findOwnedPlan($planId, $user);

        $details = $this->findPlanDetailsRows($plan, $user);
        [$sessionIndex] = $this->findDetailRowById($details, $detailId);

        $sessions = $this->mapDetailsToSessions($details);
        $doneMap = $this->mapDoneByIndex($details);

        array_splice($sessions, $sessionIndex, 1);
        array_splice($doneMap, $sessionIndex, 1);

        $this->replaceService->replaceForPlan($plan, $user, $sessions, $doneMap);

        return $this->json(['message' => 'Session deleted.']);
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
     * Returns a plan owned by the current user.
     */
    private function findOwnedPlan(int $planId, User $user): Plan
    {
        $plan = $this->planRepository->find($planId);
        if (!$plan instanceof Plan) {
            throw new NotFoundHttpException('Plan not found.');
        }

        if ($plan->getUser()->getId() !== $user->getId()) {
            throw new AccessDeniedHttpException('Forbidden for this plan.');
        }

        return $plan;
    }

    /**
     * @return array<int, PlanDetails>
     */
    private function findPlanDetailsRows(Plan $plan, User $user): array
    {
        return $this->planDetailsRepository->findBy(
            ['plan' => $plan, 'user' => $user],
            ['position' => 'ASC']
        );
    }

    /**
     * @param array<int, PlanDetails> $details
     * @return array{0:int, 1:PlanDetails}
     */
    private function findDetailRowById(array $details, int $detailId): array
    {
        foreach ($details as $idx => $row) {
            if ($row->getId() === $detailId) {
                return [$idx, $row];
            }
        }

        throw new NotFoundHttpException('Session not found.');
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

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonPayload(Request $request): array
    {
        $rawBody = trim((string) $request->getContent());
        if ($rawBody === '') {
            throw new BadRequestHttpException('Missing JSON body.');
        }

        try {
            $decoded = json_decode($rawBody, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadRequestHttpException('Invalid JSON payload.');
        }

        if (!is_array($decoded)) {
            throw new BadRequestHttpException('Payload must be a JSON object.');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeSessionPayload(array $payload, bool $partial): array
    {
        $session = [];

        $this->applyFormatField($session, $payload, $partial);
        $this->applyDateField($session, $payload, $partial);
        $this->applySessionTypeField($session, $payload, $partial);
        $this->applyPeField($session, $payload, $partial);
        $this->applyTotalMinField($session, $payload, $partial);
        $this->applyOptionalField($session, $payload, $partial);
        $this->applyDoneField($session, $payload, $partial);

        return $session;
    }

    /**
     * @param array<string, mixed> $session
     * @param array<string, mixed> $payload
     */
    private function applyFormatField(array &$session, array $payload, bool $partial): void
    {
        if ($partial && !array_key_exists('format', $payload)) {
            return;
        }

        $format = trim((string) ($payload['format'] ?? ''));
        if ($format === '') {
            throw new BadRequestHttpException('Format is required.');
        }

        $session['format'] = $format;
    }

    /**
     * @param array<string, mixed> $session
     * @param array<string, mixed> $payload
     */
    private function applyDateField(array &$session, array $payload, bool $partial): void
    {
        if ($partial && !array_key_exists('date', $payload)) {
            return;
        }

        $dateRaw = trim((string) ($payload['date'] ?? ''));
        if ($dateRaw === '') {
            $session['date'] = null;
            return;
        }

        try {
            $session['date'] = (new \DateTimeImmutable($dateRaw))->format('Y-m-d');
        } catch (\Throwable) {
            throw new BadRequestHttpException('Invalid date format. Expected yyyy-mm-dd.');
        }
    }

    /**
     * @param array<string, mixed> $session
     * @param array<string, mixed> $payload
     */
    private function applySessionTypeField(array &$session, array $payload, bool $partial): void
    {
        if ($partial && !array_key_exists('sessionType', $payload) && !array_key_exists('session_type', $payload) && !array_key_exists('type', $payload)) {
            return;
        }

        $sessionTypeRaw = $payload['sessionType'] ?? ($payload['session_type'] ?? ($payload['type'] ?? null));
        $session['sessionType'] = $this->nullableTrimmedString($sessionTypeRaw);
    }

    /**
     * @param array<string, mixed> $session
     * @param array<string, mixed> $payload
     */
    private function applyPeField(array &$session, array $payload, bool $partial): void
    {
        if ($partial && !array_key_exists('pe', $payload)) {
            return;
        }

        $session['pe'] = $this->nullableTrimmedString($payload['pe'] ?? null);
    }

    /**
     * @param array<string, mixed> $session
     * @param array<string, mixed> $payload
     */
    private function applyTotalMinField(array &$session, array $payload, bool $partial): void
    {
        if ($partial && !array_key_exists('totalMin', $payload) && !array_key_exists('total', $payload)) {
            return;
        }

        $totalRaw = $payload['totalMin'] ?? ($payload['total'] ?? null);
        if ($totalRaw === '' || $totalRaw === null) {
            $session['totalMin'] = null;
            return;
        }

        if (!is_numeric($totalRaw)) {
            throw new BadRequestHttpException('Invalid total duration value.');
        }

        $session['totalMin'] = (int) $totalRaw;
    }

    /**
     * @param array<string, mixed> $session
     * @param array<string, mixed> $payload
     */
    private function applyOptionalField(array &$session, array $payload, bool $partial): void
    {
        if ($partial && !array_key_exists('isOptional', $payload) && !array_key_exists('optional', $payload) && !array_key_exists('opt', $payload)) {
            return;
        }

        $optionalRaw = $payload['isOptional'] ?? ($payload['optional'] ?? ($payload['opt'] ?? false));
        $session['isOptional'] = (bool) $optionalRaw;
    }

    /**
     * @param array<string, mixed> $session
     * @param array<string, mixed> $payload
     */
    private function applyDoneField(array &$session, array $payload, bool $partial): void
    {
        if ($partial && !array_key_exists('done', $payload) && !array_key_exists('isDone', $payload)) {
            return;
        }

        $doneRaw = $payload['done'] ?? ($payload['isDone'] ?? false);
        $session['done'] = (bool) $doneRaw;
    }

    private function nullableTrimmedString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
