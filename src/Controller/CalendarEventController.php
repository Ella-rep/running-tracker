<?php

namespace App\Controller;

use App\Entity\CalendarEvent;
use App\Repository\CalendarEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/calendar/events')]
#[IsGranted('ROLE_USER')]
final class CalendarEventController extends AbstractController
{
    private const MSG_UNAUTHENTICATED = 'Utilisateur non connecte.';

    #[Route('', name: 'api_calendar_events_list', methods: ['GET'])]
    public function list(CalendarEventRepository $repository): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['items' => []], 401);
        }

        $items = array_map(
            static fn (CalendarEvent $event) => [
                'id' => $event->getId(),
                'date' => $event->getEventDate(),
                'title' => $event->getTitle(),
            ],
            $repository->findByUser($user)
        );

        return $this->json(['items' => $items]);
    }

    #[Route('', name: 'api_calendar_events_create', methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['message' => self::MSG_UNAUTHENTICATED], 401);
        }

        $payload = $this->decodePayload($request);
        $date = trim((string) ($payload['date'] ?? ''));
        $title = trim((string) ($payload['title'] ?? ''));

        $error = $this->validatePayload($date, $title);
        if ($error !== null) {
            return $this->json(['message' => $error], 422);
        }

        $event = (new CalendarEvent())
            ->setUser($user)
            ->setEventDate($date)
            ->setTitle($title)
            ->setCreatedAt(new \DateTimeImmutable())
            ->setUpdatedAt(null);

        $em->persist($event);
        $em->flush();

        return $this->json([
            'id' => $event->getId(),
            'date' => $event->getEventDate(),
            'title' => $event->getTitle(),
        ], 201);
    }

    #[Route('/{id}', name: 'api_calendar_events_update', methods: ['PUT'])]
    public function update(int $id, Request $request, CalendarEventRepository $repository, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        $response = null;

        if (!$user) {
            $response = $this->json(['message' => self::MSG_UNAUTHENTICATED], 401);
        }

        $event = null;
        if ($response === null) {
            $event = $repository->find($id);
            if (!$event || $event->getUser() !== $user) {
                $response = $this->json(['message' => 'Evenement introuvable.'], 404);
            }
        }

        if ($response === null && $event instanceof CalendarEvent) {
            $payload = $this->decodePayload($request);
            $date = trim((string) ($payload['date'] ?? $event->getEventDate()));
            $title = trim((string) ($payload['title'] ?? $event->getTitle()));

            $error = $this->validatePayload($date, $title);
            if ($error !== null) {
                $response = $this->json(['message' => $error], 422);
            } else {
                $event
                    ->setEventDate($date)
                    ->setTitle($title)
                    ->setUpdatedAt(new \DateTimeImmutable());

                $em->flush();

                $response = $this->json([
                    'id' => $event->getId(),
                    'date' => $event->getEventDate(),
                    'title' => $event->getTitle(),
                ]);
            }
        }

        return $response ?? $this->json(['message' => 'Erreur inattendue.'], 500);
    }

    #[Route('/{id}', name: 'api_calendar_events_delete', methods: ['DELETE'])]
    public function delete(int $id, CalendarEventRepository $repository, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['message' => self::MSG_UNAUTHENTICATED], 401);
        }

        $event = $repository->find($id);
        if (!$event || $event->getUser() !== $user) {
            return $this->json(['message' => 'Evenement introuvable.'], 404);
        }

        $em->remove($event);
        $em->flush();

        return $this->json(null, 204);
    }

    /** @return array<string,mixed> */
    private function decodePayload(Request $request): array
    {
        $raw = $request->getContent();
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function validatePayload(string $date, string $title): ?string
    {
        $error = null;

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $error = 'Date invalide (format attendu: yyyy-mm-dd).';
        } elseif ($title === '') {
            $error = 'Le titre est obligatoire.';
        } elseif (strlen($title) > 160) {
            $error = 'Le titre ne doit pas depasser 160 caracteres.';
        }

        return $error;
    }
}
