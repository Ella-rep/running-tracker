<?php

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/calendar/events')]
#[IsGranted('ROLE_USER')]
/**
 * Exposes CRUD API endpoints for user calendar events.
 */
final class CalendarEventController extends AbstractController
{
    private const MSG_UNAUTHENTICATED = 'Utilisateur non connecte.';

    /**
     * Lists calendar events for the authenticated user.
     */
    #[Route('', name: 'api_calendar_events_list', methods: ['GET'])]
    public function list(\App\Service\CalendarEventService $calendarEventService): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        if (!$user instanceof User) {
            return $this->json(['items' => []], 401);
        }

        return $this->json([
            'items' => $calendarEventService->listByUser($user),
        ]);
    }

    /**
     * Creates a calendar event for the authenticated user.
     */
    #[Route('', name: 'api_calendar_events_create', methods: ['POST'])]
    public function create(Request $request, \App\Service\CalendarEventService $calendarEventService): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        if (!$user instanceof User) {
            return $this->json(['message' => self::MSG_UNAUTHENTICATED], 401);
        }

        try {
            $payload = $this->decodePayload($request);
        } catch (\JsonException) {
            return $this->json(['message' => 'Payload JSON invalide.'], 400);
        }

        $result = $calendarEventService->createByUser($user, $payload);
        return $this->json($result['payload'], $result['status']);
    }

    /**
     * Updates a calendar event owned by the authenticated user.
     */
    #[Route('/{id}', name: 'api_calendar_events_update', methods: ['PUT'])]
    public function update(int $id, Request $request, \App\Service\CalendarEventService $calendarEventService): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        if (!$user instanceof User) {
            return $this->json(['message' => self::MSG_UNAUTHENTICATED], 401);
        }

        try {
            $payload = $this->decodePayload($request);
        } catch (\JsonException) {
            return $this->json(['message' => 'Payload JSON invalide.'], 400);
        }

        $result = $calendarEventService->updateByUser($user, $id, $payload);
        return $this->json($result['payload'], $result['status']);
    }

    /**
     * Deletes a calendar event owned by the authenticated user.
     */
    #[Route('/{id}', name: 'api_calendar_events_delete', methods: ['DELETE'])]
    public function delete(int $id, \App\Service\CalendarEventService $calendarEventService): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        if (!$user instanceof User) {
            return $this->json(['message' => self::MSG_UNAUTHENTICATED], 401);
        }

        $result = $calendarEventService->deleteByUser($user, $id);
        return $this->json($result['payload'], $result['status']);
    }

    /**
     * @return array<string,mixed>
     * @throws \JsonException
     */
    private function decodePayload(Request $request): array
    {
        $raw = $request->getContent();
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }

    private function getAuthenticatedUser(): ?User
    {
        $user = $this->getUser();
        return $user instanceof User ? $user : null;
    }
}
