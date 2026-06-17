<?php

namespace App\Controller;

use App\Entity\Plan;
use App\Entity\PlanDetails;
use App\Entity\PlanProgress;
use App\Entity\User;
use App\Repository\PlanDetailsRepository;
use App\Repository\PlanProgressRepository;
use App\Repository\PlanRepository;
use App\Service\PlanSessionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Renders the training plans page shell.
 */
class PlansController extends AbstractController
{
    private const FLASH_ERROR = 'error';
    private const FLASH_SUCCESS = 'success';

    /**
     * Displays the plans page.
     */
    #[Route('/plans', name: 'app_plans', methods: ['GET'])]
    #[Route('/plans/{planId<\d+>}', name: 'app_plans_detail', methods: ['GET'])]
    public function index(
        PlanRepository $planRepository,
        PlanDetailsRepository $planDetailsRepository,
        PlanSessionService $planSessionService,
        ?int $planId = null,
    ): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->render('plans/index.html.twig', [
                'username' => null,
                'initialPlanId' => $planId,
                'plansView' => [],
                'selectedPlanView' => null,
                'requestedPlanNotFound' => $planId !== null,
                'hasExamplePlan' => false,
                'starterPlaceholders' => $planSessionService->getPlaceholderSessions(),
            ]);
        }

        /** @var array<int, Plan> $plans */
        $plans = $planRepository->findBy(['user' => $user], ['name' => 'ASC']);

        $detailsRows = $planDetailsRepository->createQueryBuilder('d')
            ->andWhere('d.user = :user')
            ->setParameter('user', $user)
            ->orderBy('d.position', 'ASC')
            ->getQuery()
            ->getResult();

        /** @var array<int, PlanDetails> $detailsRows */
        $detailsByPlanId = [];
        foreach ($detailsRows as $detail) {
            $ownerPlanId = $detail->getPlan()->getId();
            if ($ownerPlanId === null) {
                continue;
            }
            $detailsByPlanId[$ownerPlanId] ??= [];
            $detailsByPlanId[$ownerPlanId][] = $detail;
        }

        $plansById = [];
        foreach ($plans as $plan) {
            $id = $plan->getId();
            if ($id !== null) {
                $plansById[$id] = $plan;
            }
        }

        [$selectedPlanView, $requestedPlanNotFound] = $this->buildSelectedPlanView(
            $planId,
            $plansById,
            $detailsByPlanId,
        );

        $hasExamplePlan = false;
        foreach ($plans as $plan) {
            if ($this->isExamplePlanName($plan->getName())) {
                $hasExamplePlan = true;
                break;
            }
        }

        return $this->render('plans/index.html.twig', [
            'username' => $this->getUser()?->getUserIdentifier(),
            'initialPlanId' => $planId,
            'plansView' => $this->buildPlansView($plans, $detailsByPlanId),
            'selectedPlanView' => $selectedPlanView,
            'requestedPlanNotFound' => $requestedPlanNotFound,
            'hasExamplePlan' => $hasExamplePlan,
            'starterPlaceholders' => $planSessionService->getPlaceholderSessions(),
        ]);
    }

    #[Route('/plans/create', name: 'app_plans_create', methods: ['POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $entityManager,
        PlanRepository $planRepository,
    ): RedirectResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        if (!$this->isCsrfTokenValid('plans.create', (string) $request->request->get('_token', ''))) {
            $this->addFlash(self::FLASH_ERROR, 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_plans');
        }

        $name = trim((string) $request->request->get('name', ''));
        if ($name === '') {
            $this->addFlash(self::FLASH_ERROR, 'Le nom du plan est obligatoire.');
            return $this->redirectToRoute('app_plans');
        }

        $existing = $planRepository->findOneBy(['user' => $user, 'name' => $name]);
        if ($existing instanceof Plan) {
            $this->addFlash(self::FLASH_ERROR, 'Un plan avec ce nom existe déjà.');
            return $this->redirectToRoute('app_plans_detail', ['planId' => $existing->getId()]);
        }

        $plan = (new Plan())
            ->setUser($user)
            ->setName($name)
            ->setDashboardTracked(!$this->isExamplePlanName($name));

        $entityManager->persist($plan);
        $entityManager->flush();

        // Starter/template sessions are no longer persisted: empty plans show
        // non-persisted placeholder suggestions instead.
        $this->addFlash(self::FLASH_SUCCESS, 'Plan créé.');

        return $this->redirectToRoute('app_plans_detail', ['planId' => $plan->getId()]);
    }

    #[Route('/plans/{planId<\d+>}/duplicate', name: 'app_plans_duplicate', methods: ['POST'])]
    public function duplicate(
        int $planId,
        Request $request,
        PlanRepository $planRepository,
        PlanDetailsRepository $planDetailsRepository,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $plan = $planRepository->find($planId);
        if (!$plan instanceof Plan || $plan->getUser()->getId() !== $user->getId()) {
            $this->addFlash(self::FLASH_ERROR, 'Plan introuvable.');
            return $this->redirectToRoute('app_plans');
        }

        if (!$this->isCsrfTokenValid('plans.duplicate.' . $planId, (string) $request->request->get('_token', ''))) {
            $this->addFlash(self::FLASH_ERROR, 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_plans_detail', ['planId' => $planId]);
        }

        $copy = $this->duplicatePlanEntity($plan, $user, $planRepository, $planDetailsRepository, $entityManager);
        $this->addFlash(self::FLASH_SUCCESS, 'Plan dupliqué (sans dates, séances non effectuées).');

        return $this->redirectToRoute('app_plans_detail', ['planId' => $copy->getId()]);
    }

    #[Route('/api/plans/{planId<\d+>}/duplicate', name: 'api_plans_duplicate', methods: ['POST'])]
    public function apiDuplicate(
        int $planId,
        PlanRepository $planRepository,
        PlanDetailsRepository $planDetailsRepository,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'Utilisateur non authentifié.'], 401);
        }

        $plan = $planRepository->find($planId);
        if (!$plan instanceof Plan || $plan->getUser()->getId() !== $user->getId()) {
            return $this->json(['message' => 'Plan introuvable.'], 404);
        }

        $copy = $this->duplicatePlanEntity($plan, $user, $planRepository, $planDetailsRepository, $entityManager);

        return $this->json(['id' => $copy->getId(), 'name' => $copy->getName()]);
    }

    #[Route('/api/plans/{planId<\d+>}/complete', name: 'api_plans_complete', methods: ['POST'])]
    public function apiComplete(
        int $planId,
        Request $request,
        PlanRepository $planRepository,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'Utilisateur non authentifié.'], 401);
        }

        $plan = $planRepository->find($planId);
        if (!$plan instanceof Plan || $plan->getUser()->getId() !== $user->getId()) {
            return $this->json(['message' => 'Plan introuvable.'], 404);
        }

        $completed = true;
        try {
            $data = json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR);
            if (is_array($data) && array_key_exists('completed', $data)) {
                $completed = (bool) $data['completed'];
            }
        } catch (\JsonException) {
            // Empty/invalid body => default to marking complete.
        }

        // Manual completion only: sessions are intentionally left untouched.
        $plan->setIsCompleted($completed);
        $entityManager->flush();

        return $this->json(['id' => $plan->getId(), 'isCompleted' => $plan->isCompleted()]);
    }

    /**
     * Clones a plan and its sessions: same format/type/pe/duration, but with no
     * dates and every session reset to "not done" / "not cancelled".
     */
    private function duplicatePlanEntity(
        Plan $plan,
        User $user,
        PlanRepository $planRepository,
        PlanDetailsRepository $planDetailsRepository,
        EntityManagerInterface $entityManager,
    ): Plan {
        $baseName = $plan->getName() . ' (copie)';
        $name = $baseName;
        $suffix = 2;
        while ($planRepository->findOneBy(['user' => $user, 'name' => $name]) instanceof Plan) {
            $name = $baseName . ' ' . $suffix;
            $suffix++;
        }

        $copy = (new Plan())
            ->setUser($user)
            ->setName($name)
            ->setDashboardTracked(true);
        $entityManager->persist($copy);

        $sessions = $planDetailsRepository->createQueryBuilder('d')
            ->andWhere('d.plan = :plan')
            ->setParameter('plan', $plan)
            ->orderBy('d.position', 'ASC')
            ->getQuery()
            ->getResult();

        /** @var array<int, PlanDetails> $sessions */
        foreach ($sessions as $session) {
            $detail = (new PlanDetails())
                ->setUser($user)
                ->setPlan($copy)
                ->setPosition($session->getPosition())
                ->setSem($session->getSem())
                ->setSessionDate(null)
                ->setFormat($session->getFormat())
                ->setSessionType($session->getSessionType())
                ->setPe($session->getPe())
                ->setTotalMin($session->getTotalMin())
                ->setIsOptional($session->isOptional())
                ->setIsDone(false)
                ->setIsCancelled(false);
            $entityManager->persist($detail);
        }

        $entityManager->flush();

        return $copy;
    }

    #[Route('/plans/{planId<\d+>}/rename', name: 'app_plans_rename', methods: ['POST'])]
    public function rename(
        int $planId,
        Request $request,
        PlanRepository $planRepository,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $plan = $planRepository->find($planId);
        if (!$plan instanceof Plan || $plan->getUser()->getId() !== $user->getId()) {
            $this->addFlash(self::FLASH_ERROR, 'Plan introuvable.');
            return $this->redirectToRoute('app_plans');
        }

        if (!$this->isCsrfTokenValid('plans.rename.' . $planId, (string) $request->request->get('_token', ''))) {
            $this->addFlash(self::FLASH_ERROR, 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_plans_detail', ['planId' => $planId]);
        }

        $name = trim((string) $request->request->get('name', ''));
        if ($name === '') {
            $this->addFlash(self::FLASH_ERROR, 'Le nom du plan est obligatoire.');
            return $this->redirectToRoute('app_plans_detail', ['planId' => $planId]);
        }

        $existing = $planRepository->findOneBy(['user' => $user, 'name' => $name]);
        if ($existing instanceof Plan && $existing->getId() !== $planId) {
            $this->addFlash(self::FLASH_ERROR, 'Un plan avec ce nom existe déjà.');
            return $this->redirectToRoute('app_plans_detail', ['planId' => $planId]);
        }

        $plan->setName($name);
        $entityManager->flush();
        $this->addFlash(self::FLASH_SUCCESS, 'Plan renommé.');

        return $this->redirectToRoute('app_plans_detail', ['planId' => $planId]);
    }

    #[Route('/plans/{planId<\d+>}/delete', name: 'app_plans_delete', methods: ['POST'])]
    public function delete(
        int $planId,
        Request $request,
        PlanRepository $planRepository,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $plan = $planRepository->find($planId);
        if (!$plan instanceof Plan || $plan->getUser()->getId() !== $user->getId()) {
            $this->addFlash(self::FLASH_ERROR, 'Plan introuvable.');
            return $this->redirectToRoute('app_plans');
        }

        if (!$this->isCsrfTokenValid('plans.delete.' . $planId, (string) $request->request->get('_token', ''))) {
            $this->addFlash(self::FLASH_ERROR, 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_plans_detail', ['planId' => $planId]);
        }

        $entityManager->remove($plan);
        $entityManager->flush();
        $this->addFlash(self::FLASH_SUCCESS, 'Plan supprimé.');

        return $this->redirectToRoute('app_plans');
    }

    #[Route('/api/plans/{planId<\d+>}/delete', name: 'api_plans_delete', methods: ['DELETE'])]
    public function apiDelete(
        int $planId,
        PlanRepository $planRepository,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'Utilisateur non authentifié.'], 401);
        }

        $plan = $planRepository->find($planId);
        if (!$plan instanceof Plan || $plan->getUser()->getId() !== $user->getId()) {
            return $this->json(['message' => 'Plan introuvable.'], 404);
        }

        $entityManager->remove($plan);
        $entityManager->flush();

        return $this->json(['message' => 'Plan supprimé.', 'id' => $planId]);
    }

    #[Route('/plans/{planId<\d+>}/sessions/add', name: 'app_plans_session_add', methods: ['POST'])]
    public function addSession(
        int $planId,
        Request $request,
        PlanRepository $planRepository,
        PlanDetailsRepository $planDetailsRepository,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $plan = $planRepository->find($planId);
        if (!$plan instanceof Plan || $plan->getUser()->getId() !== $user->getId()) {
            $this->addFlash(self::FLASH_ERROR, 'Plan introuvable.');
            return $this->redirectToRoute('app_plans');
        }

        if (!$this->isCsrfTokenValid('plans.session.add.' . $planId, (string) $request->request->get('_token', ''))) {
            $this->addFlash(self::FLASH_ERROR, 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_plans_detail', ['planId' => $planId]);
        }

        $last = $planDetailsRepository->createQueryBuilder('d')
            ->andWhere('d.plan = :plan')
            ->setParameter('plan', $plan)
            ->orderBy('d.position', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        $nextPosition = $last instanceof PlanDetails ? ($last->getPosition() + 1) : 1;
        $nextSem = $last instanceof PlanDetails ? ($last->getSem() ?? 1) : 1;

        $detail = (new PlanDetails())
            ->setUser($user)
            ->setPlan($plan)
            ->setPosition($nextPosition)
            ->setSem($nextSem)
            ->setSessionDate(null)
            ->setFormat("45'@Z2")
            ->setSessionType('EF')
            ->setPe('3/10')
            ->setTotalMin(45)
            ->setIsOptional(false)
            ->setIsDone(false);

        $entityManager->persist($detail);
        $entityManager->flush();
        $this->addFlash(self::FLASH_SUCCESS, 'Séance ajoutée.');

        return $this->redirectToRoute('app_plans_detail', ['planId' => $planId]);
    }

    #[Route('/plans/{planId<\d+>}/sessions/{sessionId<\d+>}/toggle', name: 'app_plans_session_toggle', methods: ['POST'])]
    public function toggleSession(
        int $planId,
        int $sessionId,
        Request $request,
        PlanRepository $planRepository,
        PlanDetailsRepository $planDetailsRepository,
        PlanProgressRepository $planProgressRepository,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $plan = $planRepository->find($planId);
        $session = $planDetailsRepository->find($sessionId);
        if (!$plan instanceof Plan || !$session instanceof PlanDetails || $plan->getUser()->getId() !== $user->getId() || $session->getPlan()->getId() !== $planId) {
            $this->addFlash(self::FLASH_ERROR, 'Séance introuvable.');
            return $this->redirectToRoute('app_plans');
        }

        if (!$this->isCsrfTokenValid('plans.session.toggle.' . $sessionId, (string) $request->request->get('_token', ''))) {
            $this->addFlash(self::FLASH_ERROR, 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_plans_detail', ['planId' => $planId]);
        }

        $done = !$session->isDone();
        $session->setIsDone($done);

        $sessionIndex = max(0, $session->getPosition() - 1);
        $progress = $planProgressRepository->findOneBy([
            'user' => $user,
            'planKey' => (string) $planId,
            'sessionIndex' => $sessionIndex,
        ]);

        if (!$progress instanceof PlanProgress) {
            $progress = (new PlanProgress())
                ->setUser($user)
                ->setPlanKey((string) $planId)
                ->setSessionIndex($sessionIndex);
            $entityManager->persist($progress);
        }

        $progress->setDone($done);

        $entityManager->flush();
        $this->addFlash(self::FLASH_SUCCESS, $done ? 'Séance validée.' : 'Séance décochée.');

        return $this->redirectToRoute('app_plans_detail', ['planId' => $planId]);
    }

    #[Route('/plans/{planId<\d+>}/sessions/{sessionId<\d+>}/delete', name: 'app_plans_session_delete', methods: ['POST'])]
    public function deleteSession(
        int $planId,
        int $sessionId,
        Request $request,
        PlanRepository $planRepository,
        PlanDetailsRepository $planDetailsRepository,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $plan = $planRepository->find($planId);
        $session = $planDetailsRepository->find($sessionId);
        if (!$plan instanceof Plan || !$session instanceof PlanDetails || $plan->getUser()->getId() !== $user->getId() || $session->getPlan()->getId() !== $planId) {
            $this->addFlash(self::FLASH_ERROR, 'Séance introuvable.');
            return $this->redirectToRoute('app_plans');
        }

        if (!$this->isCsrfTokenValid('plans.session.delete.' . $sessionId, (string) $request->request->get('_token', ''))) {
            $this->addFlash(self::FLASH_ERROR, 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_plans_detail', ['planId' => $planId]);
        }

        $entityManager->remove($session);
        $entityManager->flush();
        $this->addFlash(self::FLASH_SUCCESS, 'Séance supprimée.');

        return $this->redirectToRoute('app_plans_detail', ['planId' => $planId]);
    }

    #[Route('/api/plans/{planId<\d+>}/tracking', name: 'api_plans_tracking', methods: ['PATCH'])]
    public function updateTracking(
        int $planId,
        Request $request,
        PlanRepository $planRepository,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'Utilisateur non authentifié.'], 401);
        }

        $plan = $planRepository->find($planId);
        if (!$plan instanceof Plan || $plan->getUser()->getId() !== $user->getId()) {
            return $this->json(['message' => 'Plan introuvable.'], 404);
        }

        try {
            $data = json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['message' => 'Payload JSON invalide.'], 400);
        }

        if (!is_array($data) || !array_key_exists('tracked', $data)) {
            return $this->json(['message' => 'Le champ tracked est requis.'], 400);
        }

        $plan->setDashboardTracked((bool) $data['tracked']);
        $entityManager->flush();

        return $this->json([
            'id' => $plan->getId(),
            'tracked' => $plan->isDashboardTracked(),
            'name' => $plan->getName(),
        ]);
    }

    /** @param array<int, Plan> $plans @param array<int, array<int, PlanDetails>> $detailsByPlanId @return array<int, array{id:int,title:string,sub:string,total:int,done:int,pct:int}> */
    private function buildPlansView(array $plans, array $detailsByPlanId): array
    {
        $cards = [];

        foreach ($plans as $plan) {
            $planId = $plan->getId();
            if ($planId === null) {
                continue;
            }

            $details = $detailsByPlanId[$planId] ?? [];
            // Cancelled sessions are deliberately skipped: exclude them so a plan
            // with cancelled sessions can still reach 100%.
            $active = array_filter($details, static fn (PlanDetails $d): bool => !$d->isCancelled());
            $total = count($active);
            $done = count(array_filter($active, static fn (PlanDetails $d): bool => $d->isDone()));
            $pct = $total > 0 ? (int) round(($done / $total) * 100) : 0;
            $name = $plan->getName();

            $cards[] = [
                'id' => $planId,
                'title' => $this->isExamplePlanName($name) ? 'Plan de départ (exemple)' : $name,
                'sub' => $this->isExamplePlanName($name) ? 'Plan fourni avec l\'application · blocs hebdomadaires' : '',
                'total' => $total,
                'done' => $done,
                'pct' => $pct,
                'completed' => $plan->isCompleted(),
            ];
        }

        return $cards;
    }

    /** @param array<int, Plan> $plansById @param array<int, array<int, PlanDetails>> $detailsByPlanId @return array{0:?array<string,mixed>,1:bool} */
    private function buildSelectedPlanView(?int $planId, array $plansById, array $detailsByPlanId): array
    {
        if ($planId === null) {
            return [null, false];
        }

        $plan = $plansById[$planId] ?? null;
        if (!$plan instanceof Plan) {
            return [null, true];
        }

        $weeks = [];
        $sessions = $detailsByPlanId[$planId] ?? [];
        foreach ($sessions as $detail) {
            $weekNumber = $detail->getSem() ?? (int) floor(max(0, $detail->getPosition() - 1) / 4) + 1;
            $weeks[$weekNumber] ??= [];
            $weeks[$weekNumber][] = [
                'id' => $detail->getId(),
                'position' => $detail->getPosition(),
                'date' => $detail->getSessionDate(),
                'format' => $detail->getFormat(),
                'sessionType' => $detail->getSessionType(),
                'pe' => $detail->getPe(),
                'totalMin' => $detail->getTotalMin(),
                'isOptional' => $detail->isOptional(),
                'isDone' => $detail->isDone(),
            ];
        }

        $weekBlocks = [];
        foreach ($weeks as $weekNumber => $weekSessions) {
            usort($weekSessions, static function (array $a, array $b): int {
                $aDate = $a['date'] instanceof \DateTimeInterface ? $a['date']->format('Y-m-d') : '9999-12-31';
                $bDate = $b['date'] instanceof \DateTimeInterface ? $b['date']->format('Y-m-d') : '9999-12-31';
                if ($aDate !== $bDate) {
                    return strcmp($aDate, $bDate);
                }

                return (int) (($a['position'] ?? 0) <=> ($b['position'] ?? 0));
            });

            $weekDate = null;
            foreach ($weekSessions as $weekSession) {
                if ($weekSession['date'] instanceof \DateTimeInterface) {
                    $weekDate = $weekSession['date'];
                    break;
                }
            }

            $weekBlocks[] = [
                'weekNumber' => $weekNumber,
                'weekDate' => $weekDate,
                'sessions' => $weekSessions,
            ];
        }

        usort($weekBlocks, static function (array $a, array $b): int {
            $aDate = $a['weekDate'] instanceof \DateTimeInterface ? $a['weekDate']->format('Y-m-d') : '9999-12-31';
            $bDate = $b['weekDate'] instanceof \DateTimeInterface ? $b['weekDate']->format('Y-m-d') : '9999-12-31';
            if ($aDate !== $bDate) {
                return strcmp($aDate, $bDate);
            }

            return (int) (($a['weekNumber'] ?? 0) <=> ($b['weekNumber'] ?? 0));
        });

        $name = $plan->getName();

        return [[
            'id' => $plan->getId(),
            'title' => $this->isExamplePlanName($name) ? 'Plan de depart (exemple)' : $name,
            'sub' => $this->isExamplePlanName($name) ? 'Plan fourni avec l\'application · blocs hebdomadaires' : '',
            'weeks' => $weekBlocks,
        ], false];
    }

    private function isExamplePlanName(string $name): bool
    {
        return strtolower(trim($name)) === 'starter';
    }
}
