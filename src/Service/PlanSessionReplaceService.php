<?php

namespace App\Service;

use App\Entity\Plan;
use App\Entity\PlanDetails;
use App\Entity\User;
use App\Repository\PlanDetailsRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Replaces persisted plan sessions with a normalized new set.
 */
final class PlanSessionReplaceService
{
    /**
     * @param EntityManagerInterface $em Entity manager used for persistence operations.
     * @param PlanDetailsRepository $planDetailsRepository Repository for plan detail records.
     */
    public function __construct(
        private EntityManagerInterface $em,
        private PlanDetailsRepository $planDetailsRepository,
    ) {
    }

    /**
     * Replaces all sessions for a user/plan pair while preserving explicit done flags.
     *
     * @param array<int, array<string, mixed>> $sessions
     * @param array<int|string, bool> $doneMap
     */
    public function replaceForPlan(Plan $plan, User $user, array $sessions, array $doneMap = []): void
    {
        // Full replacement keeps ordering/index consistency when plan templates change.
        $qb = $this->planDetailsRepository->createQueryBuilder('d');
        $qb->delete()
            ->where('d.plan = :plan')
            ->andWhere('d.user = :user')
            ->setParameter('plan', $plan)
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();

        $weekIndexByMonday = $this->buildTrainingWeekIndexByMonday($sessions);

        foreach (array_values($sessions) as $idx => $session) {
            if (!is_array($session)) {
                continue;
            }

            $sessionDate = $this->toDate($session['date'] ?? null);
            $detail = new PlanDetails();
            $detail->setUser($user);
            $detail->setPlan($plan);
            $detail->setPosition($idx + 1);
            $detail->setSem($this->resolveSem($session, $sessionDate, $weekIndexByMonday));
            $detail->setSessionDate($sessionDate);
            $detail->setFormat($this->asString($session['format'] ?? "45'@Z2", "45'@Z2"));
            $detail->setSessionType($this->extractSessionType($session));
            $detail->setPe($this->nullableString($session['pe'] ?? null));
            $detail->setTotalMin($this->nullableInt($session['totalMin'] ?? ($session['total'] ?? null)));
            $detail->setIsOptional((bool) ($session['isOptional'] ?? ($session['optional'] ?? ($session['opt'] ?? false))));
            $detail->setIsDone($this->resolveDone($doneMap, $idx));
            $this->em->persist($detail);
        }

        $this->em->flush();
    }

    /** @param array<int|string, bool> $doneMap */
    private function resolveDone(array $doneMap, int $idx): bool
    {
        if (array_key_exists($idx, $doneMap)) {
            return (bool) $doneMap[$idx];
        }

        $stringKey = (string) $idx;
        if (array_key_exists($stringKey, $doneMap)) {
            return (bool) $doneMap[$stringKey];
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $sessions
     * @return array<string, int>
     */
    private function buildTrainingWeekIndexByMonday(array $sessions): array
    {
        $mondayKeys = [];

        foreach ($sessions as $session) {
            if (!is_array($session)) {
                continue;
            }

            $date = $this->toDate($session['date'] ?? null);
            if (!$date) {
                continue;
            }

            $mondayKeys[] = $date->setTime(0, 0)->modify('monday this week')->format('Y-m-d');
        }

        $uniqueMondayKeys = array_values(array_unique($mondayKeys));
        sort($uniqueMondayKeys, \SORT_STRING);

        $weekIndexByMonday = [];
        foreach ($uniqueMondayKeys as $idx => $mondayKey) {
            $weekIndexByMonday[$mondayKey] = $idx + 1;
        }

        return $weekIndexByMonday;
    }

    /** @param array<string, mixed> $session */
    private function resolveSem(array $session, ?\DateTimeImmutable $sessionDate, array $weekIndexByMonday): ?int
    {
        if ($sessionDate) {
            $mondayKey = $sessionDate->setTime(0, 0)->modify('monday this week')->format('Y-m-d');
            if (array_key_exists($mondayKey, $weekIndexByMonday)) {
                return $weekIndexByMonday[$mondayKey];
            }
        }

        return $this->nullableInt($session['sem'] ?? null);
    }

    private function toDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function asString(mixed $value, string $fallback): string
    {
        $text = trim((string) $value);

        return $text === '' ? $fallback : $text;
    }

    /** @param array<string, mixed> $session */
    private function extractSessionType(array $session): ?string
    {
        $raw = $session['sessionType']
            ?? $session['session_type']
            ?? $session['type']
            ?? null;

        $text = $this->nullableString($raw);
        $normalized = null;

        if ($text !== null) {
            $ascii = strtoupper(trim((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text)));
            $ascii = preg_replace('/\s+/', ' ', $ascii) ?? $ascii;

            if ($ascii === 'EF' || str_contains($ascii, 'ENDURANCE FONDAMENTALE')) {
                $normalized = 'EF';
            } elseif ($ascii === 'FC' || str_contains($ascii, 'FRACTIONNE COURT')) {
                $normalized = 'FC';
            } elseif ($ascii === 'SL' || str_contains($ascii, 'SORTIE LONGUE')) {
                $normalized = 'SL';
            } elseif ($ascii === 'FL' || str_contains($ascii, 'FRACTIONNE LONG')) {
                $normalized = 'FL';
            } elseif ($ascii === 'T' || str_contains($ascii, 'TEMPO')) {
                $normalized = 'T';
            } elseif ($ascii === 'RACE' || str_contains($ascii, 'COURSE')) {
                $normalized = 'Race';
            } else {
                $normalized = $text;
            }
        }

        return $normalized;
    }
}

