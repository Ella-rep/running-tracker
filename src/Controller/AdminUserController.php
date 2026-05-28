<?php

namespace App\Controller;

use App\Entity\AdminAnnouncement;
use App\Entity\AdminAuditLog;
use App\Entity\User;
use App\Repository\AdminAnnouncementRepository;
use App\Repository\AdminAuditLogRepository;
use App\Repository\CalendarEventRepository;
use App\Repository\PlanRepository;
use App\Repository\PlanProgressRepository;
use App\Repository\RaceRepository;
use App\Repository\RunLogRepository;
use App\Repository\UserRepository;
use App\Service\GoogleOAuthErrorReportService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/users')]
class AdminUserController extends AbstractController
{
    private const CSRF_ERROR_MESSAGE = 'Token CSRF invalide.';
    private const PER_PAGE = 12;
    private const AUDIT_PER_PAGE = 25;
    private const LOOKBACK_7_DAYS = '-7 days';
    private const LOOKBACK_24_HOURS = '-24 hours';
    private const LOOKBACK_48_HOURS = '-48 hours';

    #[Route('', name: 'app_admin_users', methods: ['GET'])]
    public function index(
        Request $request,
        UserRepository $userRepository,
        AdminAuditLogRepository $auditLogs,
        RunLogRepository $runLogRepository,
        PlanRepository $planRepository,
        PlanProgressRepository $planProgressRepository,
        RaceRepository $raceRepository,
        CalendarEventRepository $calendarEventRepository,
        AdminAnnouncementRepository $announcementRepository,
        GoogleOAuthErrorReportService $googleOAuthErrorReportService,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $searchQuery = trim((string) $request->query->get('q', ''));
        $page = max(1, (int) $request->query->get('page', 1));
        $auditPage = max(1, (int) $request->query->get('audit_page', 1));

        $auditAction = trim((string) $request->query->get('audit_action', ''));
        $auditAdmin = trim((string) $request->query->get('audit_admin', ''));
        $auditFromRaw = trim((string) $request->query->get('audit_from', ''));
        $auditToRaw = trim((string) $request->query->get('audit_to', ''));
        $auditFrom = $this->parseDateBoundary($auditFromRaw, false);
        $auditTo = $this->parseDateBoundary($auditToRaw, true);

        $queryBuilder = $userRepository->createQueryBuilder('u')
            ->orderBy('u.createdAt', 'DESC');

        if ($searchQuery !== '') {
            $queryBuilder
                ->andWhere('LOWER(u.username) LIKE :term OR LOWER(u.email) LIKE :term')
                ->setParameter('term', '%' . mb_strtolower($searchQuery) . '%');
        }

        $countBuilder = clone $queryBuilder;
        $totalUsers = (int) $countBuilder
            ->resetDQLPart('orderBy')
            ->select('COUNT(u.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $totalPages = max(1, (int) ceil($totalUsers / self::PER_PAGE));
        $page = min($page, $totalPages);

        $users = $queryBuilder
            ->setFirstResult(($page - 1) * self::PER_PAGE)
            ->setMaxResults(self::PER_PAGE)
            ->getQuery()
            ->getResult();

        $totalAudit = $auditLogs->countFiltered(
            $auditAction !== '' ? $auditAction : null,
            $auditAdmin !== '' ? $auditAdmin : null,
            $auditFrom,
            $auditTo
        );
        $totalAuditPages = max(1, (int) ceil($totalAudit / self::AUDIT_PER_PAGE));
        $auditPage = min($auditPage, $totalAuditPages);
        $auditEntries = $auditLogs->findRecentFiltered(
            $auditAction !== '' ? $auditAction : null,
            $auditAdmin !== '' ? $auditAdmin : null,
            $auditFrom,
            $auditTo,
            self::AUDIT_PER_PAGE,
            ($auditPage - 1) * self::AUDIT_PER_PAGE
        );

        $now = new DateTimeImmutable();
        $kpiTotalUsers = (int) $userRepository->count([]);
        $kpiBundle = $this->buildUserKpiBundle($kpiTotalUsers, $now, [
            'user' => $userRepository,
            'audit' => $auditLogs,
            'runLog' => $runLogRepository,
            'plan' => $planRepository,
            'progress' => $planProgressRepository,
            'race' => $raceRepository,
            'calendar' => $calendarEventRepository,
        ]);

        $alerts = [];
        $reset24h = $auditLogs->countActionSince('user_reset_password', $now->modify(self::LOOKBACK_24_HOURS));
        if ($reset24h >= 5) {
            $alerts[] = [
                'level' => 'warning',
                'title' => 'Resets mot de passe eleves',
                'message' => sprintf('%d resets sur les 24h precedentes.', $reset24h),
            ];
        }

        $deletions24h = $auditLogs->countActionSince('user_delete', $now->modify(self::LOOKBACK_24_HOURS));
        if ($deletions24h >= 3) {
            $alerts[] = [
                'level' => 'critical',
                'title' => 'Suppressions utilisateurs anormales',
                'message' => sprintf('%d suppressions sur les 24h precedentes.', $deletions24h),
            ];
        }

        if (!$runLogRepository->hasAnyCreatedSince($now->modify(self::LOOKBACK_48_HOURS))) {
            $alerts[] = [
                'level' => 'warning',
                'title' => 'Activite log faible',
                'message' => 'Aucune nouvelle seance enregistree depuis 48h.',
            ];
        }

        $googleOauthReport = $googleOAuthErrorReportService->collectRecentErrors(24, 3);
        if ($googleOauthReport['count'] > 0) {
            $alerts[] = [
                'level' => $googleOauthReport['count'] >= 5 ? 'critical' : 'warning',
                'title' => 'Erreurs OAuth Google detectees',
                'message' => sprintf(
                    '%d erreurs sur 24h. Utilisez Maintenance pour envoyer le rapport detaille.',
                    $googleOauthReport['count']
                ),
            ];
        }

        if ($alerts === []) {
            $alerts[] = [
                'level' => 'ok',
                'title' => 'Aucune alerte critique',
                'message' => 'Les indicateurs de securite et d activite sont stables.',
            ];
        }

        $latestAnnouncement = $announcementRepository->findOneBy([], ['updatedAt' => 'DESC']);

        return $this->render('admin/users.html.twig', [
            'current_page' => 'admin_users',
            'username' => $this->getUser()?->getUserIdentifier(),
            'users' => $users,
            'search_query' => $searchQuery,
            'page' => $page,
            'per_page' => self::PER_PAGE,
            'total_users' => $totalUsers,
            'total_pages' => $totalPages,
            'kpis' => $kpiBundle['kpis'],
            'alerts' => $alerts,
            'audit_logs' => $auditEntries,
            'audit_total' => $totalAudit,
            'audit_page' => $auditPage,
            'audit_total_pages' => $totalAuditPages,
            'audit_filters' => [
                'action' => $auditAction,
                'admin' => $auditAdmin,
                'from' => $auditFromRaw,
                'to' => $auditToRaw,
            ],
            'audit_actions' => $auditLogs->findDistinctActions(),
            'latest_announcement' => $latestAnnouncement,
            'feature_usage' => $kpiBundle['feature_usage'],
        ]);
    }

    /**
     * @return array{
     *   kpis: array<string, int|float|string>,
     *   feature_usage: list<array{name: string, used: int, unused: int}>
     * }
     */
    private function buildUserKpiBundle(int $totalUsers, DateTimeImmutable $now, array $repositories): array {
        /** @var UserRepository $userRepository */
        $userRepository = $repositories['user'];
        /** @var AdminAuditLogRepository $auditLogs */
        $auditLogs = $repositories['audit'];
        /** @var RunLogRepository $runLogRepository */
        $runLogRepository = $repositories['runLog'];
        /** @var PlanRepository $planRepository */
        $planRepository = $repositories['plan'];
        /** @var PlanProgressRepository $planProgressRepository */
        $planProgressRepository = $repositories['progress'];
        /** @var RaceRepository $raceRepository */
        $raceRepository = $repositories['race'];
        /** @var CalendarEventRepository $calendarEventRepository */
        $calendarEventRepository = $repositories['calendar'];

        $since7d = $now->modify(self::LOOKBACK_7_DAYS);
        $since24h = $now->modify(self::LOOKBACK_24_HOURS);
        $completion = $planRepository->getTrackedPlanCompletionStats();
        $userActionSummary7d = $auditLogs->getUserActionSummarySince($since7d);
        $runLogs7d = $runLogRepository->countSinceDate($since7d->format('Y-m-d'));
        $userActionsCounters7d = [
            'user_create' => (int) ($userActionSummary7d['create'] ?? 0),
            'user_reset_password' => (int) ($userActionSummary7d['reset_password'] ?? 0),
            'user_delete' => (int) ($userActionSummary7d['delete'] ?? 0),
            'run_log_create' => $runLogs7d,
        ];
        $topUserAction7d = $this->resolveTopUserAction($userActionsCounters7d);
        $userActionsTotal7d = array_sum($userActionsCounters7d);
        $activeRunners7d = $runLogRepository->countDistinctUsersSinceDate($since7d->format('Y-m-d'));
        $newUsers24h = $userRepository->countCreatedSince($since24h);
        $inactiveUsers7d = max(0, $totalUsers - $activeRunners7d);
        $engagementRate7d = $totalUsers > 0 ? (int) round(($activeRunners7d / $totalUsers) * 100) : 0;
        $totalPlans = $planRepository->countAllPlans();
        $trackedPlans = $planRepository->countTrackedPlans();
        $untrackedPlans = $planRepository->countUntrackedPlans();
        $usersWithPlans = $planRepository->countDistinctUsersWithPlans();
        $usersWithAnyProgress = $planProgressRepository->countDistinctUsersWithProgress();
        $usersFollowingPlans = $planProgressRepository->countDistinctUsersWithDoneProgress();
        $usersWithInactivePlanProgress = $planProgressRepository->countDistinctUsersWithOnlyUndoneProgress();
        $usersUsingLogs = $runLogRepository->countDistinctUsersAllTime();
        $usersUsingRaces = $raceRepository->countDistinctUsersWithRaces();
        $usersUsingCalendar = $calendarEventRepository->countDistinctUsersWithEvents();
        $planAdoptionRate = $totalUsers > 0 ? (int) round(($usersWithPlans / $totalUsers) * 100) : 0;

        $featureUsage = [
            ['name' => 'Journal de sorties', 'used' => $usersUsingLogs, 'unused' => max(0, $totalUsers - $usersUsingLogs)],
            ['name' => 'Plans d entrainement', 'used' => $usersWithPlans, 'unused' => max(0, $totalUsers - $usersWithPlans)],
            ['name' => 'Suivi de progression', 'used' => $usersWithAnyProgress, 'unused' => max(0, $totalUsers - $usersWithAnyProgress)],
            ['name' => 'Courses (objectifs)', 'used' => $usersUsingRaces, 'unused' => max(0, $totalUsers - $usersUsingRaces)],
            ['name' => 'Calendrier', 'used' => $usersUsingCalendar, 'unused' => max(0, $totalUsers - $usersUsingCalendar)],
        ];

        return [
            'kpis' => [
                'total_users' => $totalUsers,
                'new_users_7d' => $userRepository->countCreatedSince($since7d),
                'new_users_24h' => $newUsers24h,
                'active_runners_7d' => $activeRunners7d,
                'inactive_users_7d' => $inactiveUsers7d,
                'engagement_rate_7d' => $engagementRate7d,
                'run_logs_7d' => $runLogs7d,
                'plan_done' => $completion['done'],
                'plan_total' => $completion['total'],
                'plan_rate' => $completion['rate'],
                'user_creates_7d' => $userActionSummary7d['create'],
                'password_resets_7d' => $userActionSummary7d['reset_password'],
                'user_deletions_7d' => $userActionSummary7d['delete'],
                'user_actions_7d_total' => $userActionsTotal7d,
                'top_action_7d' => $topUserAction7d['action'],
                'top_action_7d_label' => $this->humanizeAuditAction($topUserAction7d['action']),
                'top_action_7d_count' => $topUserAction7d['count'],
                'plans_total' => $totalPlans,
                'plans_tracked' => $trackedPlans,
                'plans_untracked' => $untrackedPlans,
                'users_with_plans' => $usersWithPlans,
                'users_following_plans' => $usersFollowingPlans,
                'users_inactive_plan_progress' => $usersWithInactivePlanProgress,
                'plan_adoption_rate' => $planAdoptionRate,
            ],
            'feature_usage' => $featureUsage,
        ];
    }

    #[Route('/audit-export.csv', name: 'app_admin_users_audit_export', methods: ['GET'])]
    public function exportAuditCsv(Request $request, AdminAuditLogRepository $auditLogs): StreamedResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $auditAction = trim((string) $request->query->get('audit_action', ''));
        $auditAdmin = trim((string) $request->query->get('audit_admin', ''));
        $auditFrom = $this->parseDateBoundary(trim((string) $request->query->get('audit_from', '')), false);
        $auditTo = $this->parseDateBoundary(trim((string) $request->query->get('audit_to', '')), true);

        $rows = $auditLogs->findRecentFiltered(
            $auditAction !== '' ? $auditAction : null,
            $auditAdmin !== '' ? $auditAdmin : null,
            $auditFrom,
            $auditTo,
            5000,
            0
        );

        $response = new StreamedResponse(function () use ($rows): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                return;
            }

            fputcsv($handle, ['date', 'admin', 'action', 'target_user_id', 'details'], ';');
            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row->getCreatedAt()->format('Y-m-d H:i:s'),
                    $row->getAdminIdentifier(),
                    $row->getAction(),
                    (string) ($row->getTargetUserId() ?? ''),
                    json_encode($row->getDetails(), JSON_UNESCAPED_UNICODE),
                ], ';');
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="admin-audit-export.csv"');

        return $response;
    }

    #[Route('/create', name: 'app_admin_users_create', methods: ['POST'])]
    public function create(
        Request $request,
        UserRepository $userRepository,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager
    ): RedirectResponse {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $username = trim((string) $request->request->get('username', ''));
        $email = strtolower(trim((string) $request->request->get('email', '')));
        $plainPassword = (string) $request->request->get('password', '');
        $isAdmin = $request->request->getBoolean('is_admin');

        $error = $this->validateCreateRequest(
            $request,
            $username,
            $email,
            $plainPassword,
            $userRepository
        );

        if ($error !== null) {
            $this->addFlash('error', $error);
            return $this->redirectToIndex($request);
        }

        $user = (new User())
            ->setUsername($username)
            ->setEmail($email)
            ->setRoles($isAdmin ? ['ROLE_ADMIN'] : []);

        $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));

        $entityManager->persist($user);
        $this->logAdminAction($entityManager, 'user_create', $user, [
            'roles' => $user->getRoles(),
        ], $request);
        $entityManager->flush();

        $this->addFlash('success', sprintf('Utilisateur "%s" cree.', $user->getUserIdentifier()));

        return $this->redirectToIndex($request);
    }

    #[Route('/{id}/toggle-admin', name: 'app_admin_users_toggle_admin', methods: ['POST'])]
    public function toggleAdmin(User $user, Request $request, EntityManagerInterface $entityManager): RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $error = null;
        if (!$this->isCsrfTokenValid('admin_toggle_' . $user->getId(), (string) $request->request->get('_token'))) {
            $error = self::CSRF_ERROR_MESSAGE;
        }

        $currentUser = $this->getUser();
        if ($error === null && $currentUser instanceof User && $currentUser->getId() === $user->getId()) {
            $error = 'Action refusee sur votre propre compte.';
        }

        if ($error !== null) {
            $this->addFlash('error', $error);
            return $this->redirectToIndex($request);
        }

        $isAdmin = in_array('ROLE_ADMIN', $user->getRoles(), true);
        $user->setRoles($isAdmin ? [] : ['ROLE_ADMIN']);

        $this->logAdminAction($entityManager, 'user_toggle_admin', $user, [
            'new_roles' => $user->getRoles(),
        ], $request);
        $entityManager->flush();

        $this->addFlash('success', $isAdmin ? 'Role admin retire.' : 'Role admin ajoute.');

        return $this->redirectToIndex($request);
    }

    #[Route('/{id}/reset-password', name: 'app_admin_users_reset_password', methods: ['POST'])]
    public function resetPassword(
        User $user,
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager
    ): RedirectResponse {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $plainPassword = (string) $request->request->get('new_password', '');

        $error = $this->validateResetPasswordRequest($request, $user, $plainPassword);
        if ($error !== null) {
            $this->addFlash('error', $error);
            return $this->redirectToIndex($request);
        }

        $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));

        $this->logAdminAction($entityManager, 'user_reset_password', $user, [], $request);
        $entityManager->flush();

        $this->addFlash('success', 'Mot de passe reinitialise.');

        return $this->redirectToIndex($request);
    }

    #[Route('/{id}/delete', name: 'app_admin_users_delete', methods: ['POST'])]
    public function delete(User $user, Request $request, EntityManagerInterface $entityManager): RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $error = null;
        if (!$this->isCsrfTokenValid('admin_delete_' . $user->getId(), (string) $request->request->get('_token'))) {
            $error = self::CSRF_ERROR_MESSAGE;
        }

        $currentUser = $this->getUser();
        if ($error === null && $currentUser instanceof User && $currentUser->getId() === $user->getId()) {
            $error = 'Suppression de votre propre compte interdite.';
        }

        if ($error !== null) {
            $this->addFlash('error', $error);
            return $this->redirectToIndex($request);
        }

        $deletedId = $user->getId();
        $deletedIdentifier = $user->getUserIdentifier();
        $this->logAdminAction($entityManager, 'user_delete', $user, [
            'deleted_user_id' => $deletedId,
            'deleted_user_identifier' => $deletedIdentifier,
        ], $request);
        $entityManager->remove($user);
        $entityManager->flush();

        $this->addFlash('success', 'Utilisateur supprime.');

        return $this->redirectToIndex($request);
    }

    #[Route('/announcement/upsert', name: 'app_admin_announcement_upsert', methods: ['POST'])]
    public function upsertAnnouncement(
        Request $request,
        EntityManagerInterface $entityManager,
        AdminAnnouncementRepository $announcementRepository
    ): RedirectResponse {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $error = null;

        if (!$this->isCsrfTokenValid('admin_announcement_upsert', (string) $request->request->get('_token'))) {
            $error = self::CSRF_ERROR_MESSAGE;
        }

        $message = trim((string) $request->request->get('message', ''));
        $title = trim((string) $request->request->get('title', 'Annonce'));
        $levelInput = trim((string) $request->request->get('level', 'info'));
        $level = strtolower($levelInput);
        $isActive = $request->request->getBoolean('is_active', true);
        $startsAtRaw = trim((string) $request->request->get('starts_at', ''));
        $endsAtRaw = trim((string) $request->request->get('ends_at', ''));

        if ($error === null) {
            $error = $this->validateAnnouncementText($message, $title);
        }

        if ($error === null) {
            $level = $this->normalizeAnnouncementLevel($level);
            if ($level === null) {
                $error = 'Niveau d annonce invalide.';
            }
        }

        $startsAt = $this->parseDateTimeLocal($startsAtRaw);
        $endsAt = $this->parseDateTimeLocal($endsAtRaw);
        if ($error === null) {
            $error = $this->validateAnnouncementDates($startsAtRaw, $endsAtRaw, $startsAt, $endsAt);
        }

        if ($error !== null) {
            $this->addFlash('error', $error);
            return $this->redirectToIndex($request);
        }

        $announcement = $announcementRepository->findOneBy([], ['updatedAt' => 'DESC']);
        if (!$announcement instanceof AdminAnnouncement) {
            $announcement = new AdminAnnouncement();
            $entityManager->persist($announcement);
        }

        $currentUser = $this->getUser();
        if ($currentUser instanceof User) {
            $announcement->setCreatedByAdminId($currentUser->getId());
        }

        if (method_exists($announcement, 'setTitle')) {
            $announcement->setTitle($title);
        }

        $announcement
            ->setMessage($message)
            ->setLevel((string) $level)
            ->setIsActive($isActive)
            ->setStartsAt($startsAt)
            ->setEndsAt($endsAt)
            ->touch();

        $this->logAdminAction($entityManager, 'announcement_upsert', null, [
            'level' => $level,
            'is_active' => $isActive,
            'starts_at' => $startsAt?->format('c'),
            'ends_at' => $endsAt?->format('c'),
        ], $request);
        $entityManager->flush();

        $this->addFlash('success', 'Annonce globale enregistree.');

        return $this->redirectToIndex($request);
    }

    #[Route('/announcement/deactivate', name: 'app_admin_announcement_deactivate', methods: ['POST'])]
    public function deactivateAnnouncement(
        Request $request,
        EntityManagerInterface $entityManager,
        AdminAnnouncementRepository $announcementRepository
    ): RedirectResponse {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_announcement_deactivate', (string) $request->request->get('_token'))) {
            $this->addFlash('error', self::CSRF_ERROR_MESSAGE);
            return $this->redirectToIndex($request);
        }

        $announcement = $announcementRepository->findCurrent();
        if (!$announcement instanceof AdminAnnouncement) {
            $this->addFlash('error', 'Aucune annonce active a desactiver.');
            return $this->redirectToIndex($request);
        }

        $announcement
            ->setIsActive(false)
            ->touch();

        $this->logAdminAction($entityManager, 'announcement_deactivate', null, [
            'announcement_id' => $announcement->getId(),
        ], $request);
        $entityManager->flush();

        $this->addFlash('success', 'Annonce globale desactivee.');

        return $this->redirectToIndex($request);
    }

    private function validateCreateRequest(
        Request $request,
        string $username,
        string $email,
        string $plainPassword,
        UserRepository $userRepository
    ): ?string {
        $error = null;

        if (!$this->isCsrfTokenValid('admin_create_user', (string) $request->request->get('_token'))) {
            $error = self::CSRF_ERROR_MESSAGE;
        }

        if ($error === null && (mb_strlen($username) < 3 || mb_strlen($username) > 64)) {
            $error = 'Le pseudo doit contenir entre 3 et 64 caracteres.';
        }

        if ($error === null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Adresse email invalide.';
        }

        if ($error === null && mb_strlen($plainPassword) < 6) {
            $error = 'Le mot de passe doit contenir au moins 6 caracteres.';
        }

        if ($error === null && $userRepository->findOneBy(['username' => $username]) instanceof User) {
            $error = 'Ce nom utilisateur existe deja.';
        }

        if ($error === null && $userRepository->findOneBy(['email' => $email]) instanceof User) {
            $error = 'Cet email existe deja.';
        }

        return $error;
    }

    private function validateResetPasswordRequest(Request $request, User $user, string $plainPassword): ?string
    {
        $error = null;

        if (!$this->isCsrfTokenValid('admin_reset_' . $user->getId(), (string) $request->request->get('_token'))) {
            $error = self::CSRF_ERROR_MESSAGE;
        }

        $currentUser = $this->getUser();
        if ($error === null && $currentUser instanceof User && $currentUser->getId() === $user->getId()) {
            $error = 'Reset de votre propre mot de passe interdit ici.';
        }

        if ($error === null && mb_strlen($plainPassword) < 6) {
            $error = 'Nouveau mot de passe: minimum 6 caracteres.';
        }

        return $error;
    }

    private function redirectToIndex(Request $request): RedirectResponse
    {
        $query = [
            'q' => trim((string) $request->query->get('q', '')),
            'page' => max(1, (int) $request->query->get('page', 1)),
        ];

        if ($query['q'] === '') {
            unset($query['q']);
        }

        return $this->redirectToRoute('app_admin_users', $query);
    }

    private function logAdminAction(
        EntityManagerInterface $entityManager,
        string $action,
        ?User $targetUser = null,
        array $details = [],
        ?Request $request = null
    ): void {
        $currentUser = $this->getUser();

        if ($request instanceof Request) {
            $details['request_meta'] = [
                'ip' => $request->getClientIp(),
                'user_agent' => mb_substr((string) $request->headers->get('User-Agent', ''), 0, 255),
                'path' => $request->getPathInfo(),
            ];
        }

        $auditLog = (new AdminAuditLog())
            ->setAction($action)
            ->setTargetUserId($targetUser?->getId())
            ->setDetails($details);

        if ($currentUser instanceof User) {
            $auditLog
                ->setAdminUserId($currentUser->getId())
                ->setAdminIdentifier($currentUser->getUserIdentifier());
        } else {
            $auditLog->setAdminIdentifier('unknown-admin');
        }

        $entityManager->persist($auditLog);
    }

    private function parseDateBoundary(string $dateRaw, bool $isEnd): ?DateTimeImmutable
    {
        $resolved = null;
        if ($dateRaw !== '') {
            $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $dateRaw);
            if ($parsed instanceof DateTimeImmutable) {
                $resolved = $isEnd ? $parsed->setTime(23, 59, 59) : $parsed->setTime(0, 0, 0);
            }
        }

        return $resolved;
    }

    private function parseDateTimeLocal(string $value): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value);
        if (!$parsed instanceof DateTimeImmutable) {
            return null;
        }

        return $parsed;
    }

    private function validateAnnouncementText(string $message, string $title): ?string
    {
        if ($message === '' || mb_strlen($message) < 6) {
            return 'Annonce trop courte (minimum 6 caracteres).';
        }

        if (mb_strlen($title) > 120) {
            return 'Titre annonce trop long (120 caracteres max).';
        }

        return null;
    }

    private function normalizeAnnouncementLevel(string $level): ?string
    {
        $levelAliases = [
            'critical' => 'danger',
            'error' => 'danger',
            'ok' => 'success',
        ];

        $normalizedLevel = $levelAliases[$level] ?? $level;

        if (!in_array($normalizedLevel, ['success', 'info', 'warning', 'danger'], true)) {
            return null;
        }

        return $normalizedLevel;
    }

    private function validateAnnouncementDates(
        string $startsAtRaw,
        string $endsAtRaw,
        ?DateTimeImmutable $startsAt,
        ?DateTimeImmutable $endsAt
    ): ?string {
        $error = null;

        if ($startsAtRaw !== '' && $startsAt === null) {
            $error = 'Date de debut invalide.';
        } elseif ($endsAtRaw !== '' && $endsAt === null) {
            $error = 'Date de fin invalide.';
        } elseif ($startsAt instanceof DateTimeImmutable && $endsAt instanceof DateTimeImmutable && $endsAt < $startsAt) {
            $error = 'La date de fin doit etre apres la date de debut.';
        }

        return $error;
    }

    private function humanizeAuditAction(?string $action): string
    {
        if ($action === null || $action === '') {
            return 'Aucune action';
        }

        $labels = [
            'user_create' => 'Creation utilisateur',
            'user_reset_password' => 'Reset mot de passe',
            'user_delete' => 'Suppression utilisateur',
            'run_log_create' => 'Enregistrement log',
            'user_toggle_admin' => 'Changement role admin',
            'announcement_upsert' => 'Mise a jour annonce',
            'announcement_deactivate' => 'Desactivation annonce',
        ];

        return $labels[$action] ?? str_replace('_', ' ', $action);
    }

    /**
     * @param array<string, int> $summary
     * @return array{action: string, count: int}
     */
    private function resolveTopUserAction(array $summary): array
    {
        arsort($summary);
        $action = (string) array_key_first($summary);
        $count = (int) ($summary[$action] ?? 0);

        if ($count <= 0) {
            return ['action' => '', 'count' => 0];
        }

        return ['action' => $action, 'count' => $count];
    }
}
