<?php

namespace App\Service;

use App\Entity\CalendarEvent;
use App\Entity\User;
use App\Repository\CalendarEventRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Handles business operations for personal calendar events.
 */
final class CalendarEventService
{
    /**
     * @param CalendarEventRepository $calendarEventRepository Repository for calendar events.
     * @param EntityManagerInterface $entityManager Entity manager used to persist event changes.
     */
    public function __construct(
        private CalendarEventRepository $calendarEventRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Lists calendar events belonging to the provided user.
     *
     * @return array<int,array{id:int|null,date:string,title:string}>
     */
    public function listByUser(User $user): array
    {
        return array_map(
            static fn (CalendarEvent $event): array => [
                'id' => $event->getId(),
                'date' => $event->getEventDate(),
                'title' => $event->getTitle(),
            ],
            $this->calendarEventRepository->findByUser($user)
        );
    }

    /**
        * Creates a new calendar event for the provided user.
        *
     * @param array<string,mixed> $payload
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function createByUser(User $user, array $payload): array
    {
        $date = $this->normalizeDateFormat(trim((string) ($payload['date'] ?? '')));
        $title = trim((string) ($payload['title'] ?? ''));

        $error = $this->validatePayload($date, $title);
        if ($error !== null) {
            return [
                'status' => 422,
                'payload' => ['message' => $error],
            ];
        }

        $event = (new CalendarEvent())
            ->setUser($user)
            ->setEventDate($date)
            ->setTitle($title)
            ->setCreatedAt(new \DateTimeImmutable())
            ->setUpdatedAt(null);

        $this->entityManager->persist($event);
        $this->entityManager->flush();

        return [
            'status' => 201,
            'payload' => [
                'id' => $event->getId(),
                'date' => $event->getEventDate(),
                'title' => $event->getTitle(),
            ],
        ];
    }

    /**
        * Updates an existing user-owned calendar event.
        *
     * @param array<string,mixed> $payload
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function updateByUser(User $user, int $id, array $payload): array
    {
        $event = $this->calendarEventRepository->find($id);
        if (!$event || $event->getUser() !== $user) {
            return [
                'status' => 404,
                'payload' => ['message' => 'Evenement introuvable.'],
            ];
        }

        $date = $this->normalizeDateFormat(trim((string) ($payload['date'] ?? $event->getEventDate())));
        $title = trim((string) ($payload['title'] ?? $event->getTitle()));

        $error = $this->validatePayload($date, $title);
        if ($error !== null) {
            return [
                'status' => 422,
                'payload' => ['message' => $error],
            ];
        }

        $event
            ->setEventDate($date)
            ->setTitle($title)
            ->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->flush();

        return [
            'status' => 200,
            'payload' => [
                'id' => $event->getId(),
                'date' => $event->getEventDate(),
                'title' => $event->getTitle(),
            ],
        ];
    }

    /**
        * Deletes an existing user-owned calendar event.
        *
     * @return array{status:int,payload:array<string,mixed>|null}
     */
    public function deleteByUser(User $user, int $id): array
    {
        $event = $this->calendarEventRepository->find($id);
        if (!$event || $event->getUser() !== $user) {
            return [
                'status' => 404,
                'payload' => ['message' => 'Evenement introuvable.'],
            ];
        }

        $this->entityManager->remove($event);
        $this->entityManager->flush();

        return [
            'status' => 204,
            'payload' => null,
        ];
    }

    private function validatePayload(string $date, string $title): ?string
    {
        $error = null;

        // Validation order is intentional to return a single, deterministic error message.
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $error = 'Date invalide (format attendu: yyyy-mm-dd).';
        } elseif ($title === '') {
            $error = 'Le titre est obligatoire.';
        } elseif (strlen($title) > 160) {
            $error = 'Le titre ne doit pas depasser 160 caracteres.';
        }

        return $error;
    }

    /**
     * Normalizes date format from user input (dd/mm/yyyy or mm/dd/yyyy) to ISO format (yyyy-mm-dd).
     *
     * @param string $dateStr Raw date string from user input.
     * @return string ISO-formatted date (yyyy-mm-dd) or original string if already valid.
     */
    private function normalizeDateFormat(string $dateStr): string
    {
        if (!$dateStr) {
            return '';
        }

        // Already in ISO format
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
            return $dateStr;
        }

        // Try French format (dd/mm/yyyy)
        $frMatch = preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $dateStr, $matches);
        if ($frMatch) {
            return "{$matches[3]}-{$matches[2]}-{$matches[1]}";
        }

        // Try ISO with time prefix (strip time part)
        if (preg_match('/^(\d{4}-\d{2}-\d{2})T/', $dateStr)) {
            return substr($dateStr, 0, 10);
        }

        // Fallback: try PHP's strtotime for other formats
        $timestamp = strtotime($dateStr);
        if ($timestamp !== false && $timestamp > 0) {
            return date('Y-m-d', $timestamp);
        }

        // Return unchanged if no format matches
        return $dateStr;
    }
}

