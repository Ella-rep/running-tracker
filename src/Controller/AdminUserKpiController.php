<?php

namespace App\Controller;

use App\Entity\PlanDetails;
use App\Entity\User;
use App\Repository\CalendarEventRepository;
use App\Repository\PlanProgressRepository;
use App\Repository\PlanRepository;
use App\Repository\RaceRepository;
use App\Repository\RunLogRepository;
use DateTimeImmutable;
use Doctrine\ORM\Query\Expr\Join;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/users')]
class AdminUserKpiController extends AbstractController
{
    private const USER_CONDITION = 'p.user = :user';
    private const TRACKED_CONDITION = 'p.dashboardTracked = true';

    #[Route('/{id<\d+>}/kpis', name: 'app_admin_users_kpis', methods: ['GET'])]
    public function userKpis(
        User $user,
        PlanRepository $planRepository,
        PlanProgressRepository $planProgressRepository,
        RunLogRepository $runLogRepository,
        RaceRepository $raceRepository,
        CalendarEventRepository $calendarEventRepository,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $now = new DateTimeImmutable();
        $since7d = $now->modify('-7 days')->format('Y-m-d');
        $today = $now->format('Y-m-d');

        $plansTotal = (int) $planRepository->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere(self::USER_CONDITION)
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        $plansTracked = (int) $planRepository->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere(self::USER_CONDITION)
            ->andWhere(self::TRACKED_CONDITION)
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        $planSessionRow = $planRepository->createQueryBuilder('p')
            ->select('SUM(CASE WHEN d.isDone = true THEN 1 ELSE 0 END) AS doneCount')
            ->addSelect('COUNT(d.id) AS totalCount')
            ->join(PlanDetails::class, 'd', Join::WITH, 'd.plan = p')
            ->andWhere(self::USER_CONDITION)
            ->andWhere(self::TRACKED_CONDITION)
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleResult();

        $planSessionsDone = (int) ($planSessionRow['doneCount'] ?? 0);
        $planSessionsTotal = (int) ($planSessionRow['totalCount'] ?? 0);
        $planSessionRate = $planSessionsTotal > 0 ? round(($planSessionsDone / $planSessionsTotal) * 100, 1) : 0.0;

        $activePlanRows = $planRepository->createQueryBuilder('p')
            ->select('p.id AS planId, p.name AS planName')
            ->addSelect('SUM(CASE WHEN d.isDone = true THEN 1 ELSE 0 END) AS doneCount')
            ->addSelect('COUNT(d.id) AS totalCount')
            ->join(PlanDetails::class, 'd', Join::WITH, 'd.plan = p')
            ->andWhere(self::USER_CONDITION)
            ->andWhere(self::TRACKED_CONDITION)
            ->groupBy('p.id, p.name')
            ->orderBy('p.id', 'DESC')
            ->setParameter('user', $user)
            ->getQuery()
            ->getArrayResult();

        $activePlan = null;
        foreach ($activePlanRows as $row) {
            $doneCount = (int) ($row['doneCount'] ?? 0);
            $totalCount = (int) ($row['totalCount'] ?? 0);
            if ($totalCount <= 0 || $doneCount >= $totalCount) {
                continue;
            }

            $activePlan = [
                'id' => (int) ($row['planId'] ?? 0),
                'name' => (string) ($row['planName'] ?? ''),
                'done' => $doneCount,
                'total' => $totalCount,
                'rate' => round(($doneCount / $totalCount) * 100, 1),
            ];
            break;
        }

        $logsRow = $runLogRepository->createQueryBuilder('r')
            ->select('COUNT(r.id) AS totalCount')
            ->addSelect('SUM(CASE WHEN r.date >= :since7d THEN 1 ELSE 0 END) AS count7d')
            ->addSelect('COALESCE(SUM(r.km), 0) AS totalKm')
            ->andWhere('r.user = :user')
            ->setParameter('user', $user)
            ->setParameter('since7d', $since7d)
            ->getQuery()
            ->getSingleResult();

        $lastLog = $runLogRepository->findOneBy(['user' => $user], ['date' => 'DESC', 'id' => 'DESC']);

        $racesRow = $raceRepository->createQueryBuilder('r')
            ->select('COUNT(r.id) AS totalCount')
            ->addSelect('SUM(CASE WHEN r.result IS NOT NULL AND r.result <> :empty THEN 1 ELSE 0 END) AS completedCount')
            ->addSelect('SUM(CASE WHEN (r.result IS NULL OR r.result = :empty) THEN 1 ELSE 0 END) AS plannedCount')
            ->addSelect('SUM(CASE WHEN (r.result IS NULL OR r.result = :empty) AND r.date >= :today THEN 1 ELSE 0 END) AS upcomingCount')
            ->andWhere('r.user = :user')
            ->setParameter('user', $user)
            ->setParameter('empty', '')
            ->setParameter('today', $today)
            ->getQuery()
            ->getSingleResult();

        $calendarEvents = (int) $calendarEventRepository->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        $hasPlanProgressData = (int) $planProgressRepository->createQueryBuilder('pp')
            ->select('COUNT(pp.id)')
            ->andWhere('pp.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult() > 0;

        return $this->json([
            'user' => [
                'id' => $user->getId(),
                'username' => $user->getUserIdentifier(),
                'email' => $user->getEmail(),
            ],
            'plans' => [
                'total' => $plansTotal,
                'tracked' => $plansTracked,
                'sessionsDone' => $planSessionsDone,
                'sessionsTotal' => $planSessionsTotal,
                'sessionRate' => $planSessionRate,
                'activePlan' => $activePlan,
            ],
            'logs' => [
                'total' => (int) ($logsRow['totalCount'] ?? 0),
                'lastDate' => $lastLog?->getDate(),
                'logs7d' => (int) ($logsRow['count7d'] ?? 0),
                'totalKm' => round((float) ($logsRow['totalKm'] ?? 0), 2),
            ],
            'courses' => [
                'total' => (int) ($racesRow['totalCount'] ?? 0),
                'completed' => (int) ($racesRow['completedCount'] ?? 0),
                'planned' => (int) ($racesRow['plannedCount'] ?? 0),
                'upcoming' => (int) ($racesRow['upcomingCount'] ?? 0),
            ],
            'calendar' => [
                'events' => $calendarEvents,
            ],
            'meta' => [
                'generatedAt' => $now->format(DATE_ATOM),
                'hasPlanProgressData' => $hasPlanProgressData,
            ],
        ]);
    }
}
