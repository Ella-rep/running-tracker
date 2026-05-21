<?php

namespace App\Service;

use App\Entity\Plan;
use App\Entity\PlanDetails;
use App\Entity\User;
use App\Repository\PlanDetailsRepository;
use Doctrine\ORM\EntityManagerInterface;

final class PlanSessionReplaceService
{
    public function __construct(
        private EntityManagerInterface $em,
        private PlanDetailsRepository $planDetailsRepository,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $sessions
     * @param array<int|string, bool> $doneMap
     */
    public function replaceForPlan(Plan $plan, User $user, array $sessions, array $doneMap = []): void
    {
        $qb = $this->planDetailsRepository->createQueryBuilder('d');
        $qb->delete()
            ->where('d.plan = :plan')
            ->andWhere('d.user = :user')
            ->setParameter('plan', $plan)
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();

        $planStartMonday = $this->getPlanStartMonday($sessions);

        foreach (array_values($sessions) as $idx => $session) {
            if (!is_array($session)) {
                continue;
            }

            $sessionDate = $this->toDate($session['date'] ?? null);
            $detail = new PlanDetails();
            $detail->setUser($user);
            $detail->setPlan($plan);
            $detail->setPosition($idx + 1);
            $detail->setSem($this->resolveSem($session, $sessionDate, $planStartMonday));
            $detail->setSessionDate($sessionDate);
            $detail->setFormat($this->asString($session['format'] ?? "45'@Z2", "45'@Z2"));
            $detail->setSessionType($this->extractSessionType($session));
            $detail->setPe($this->nullableString($session['pe'] ?? null));
            $detail->setTotalMin($this->nullableInt($session['totalMin'] ?? ($session['total'] ?? null)));
            $detail->setIsOptional((bool) ($session['isOptional'] ?? ($session['opt'] ?? false)));
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

    /** @param array<int, array<string, mixed>> $sessions */
    private function getPlanStartMonday(array $sessions): ?\DateTimeImmutable
    {
        $firstDate = null;

        foreach ($sessions as $session) {
            if (!is_array($session)) {
                continue;
            }

            $date = $this->toDate($session['date'] ?? null);
            if (!$date) {
                continue;
            }

            if ($firstDate === null || $date < $firstDate) {
                $firstDate = $date;
            }
        }

        return $firstDate?->setTime(0, 0)->modify('monday this week');
    }

    /** @param array<string, mixed> $session */
    private function resolveSem(array $session, ?\DateTimeImmutable $sessionDate, ?\DateTimeImmutable $planStartMonday): ?int
    {
        if ($sessionDate && $planStartMonday) {
            $sessionMonday = $sessionDate->setTime(0, 0)->modify('monday this week');
            $daysDiff = (int) $planStartMonday->diff($sessionMonday)->format('%r%a');

            return intdiv(max(0, $daysDiff), 7) + 1;
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
            } elseif ($ascii === 'FL' || str_contains($ascii, 'SORTIE LONGUE')) {
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

