<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\HealthPauseService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class HealthPauseController extends AbstractController
{
    #[Route('/api/health-pause/activate', name: 'api_health_pause_activate', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function activate(Request $request, HealthPauseService $service): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $body = [];
        $content = $request->getContent();
        if ($content !== '') {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        $type = isset($body['type']) && is_string($body['type']) ? $body['type'] : null;
        $estimatedDays = isset($body['estimatedDays']) && is_int($body['estimatedDays']) ? $body['estimatedDays'] : null;
        $startedAt = isset($body['startedAt']) && is_string($body['startedAt']) ? $body['startedAt'] : null;

        $service->activate($user, $type, $estimatedDays, $startedAt);

        return $this->json($service->getStatus($user), 201);
    }

    #[Route('/api/health-pause/resume', name: 'api_health_pause_resume', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function resume(HealthPauseService $service): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $service->resume($user);

        return $this->json($service->getStatus($user), 200);
    }

    #[Route('/api/health-pause/status', name: 'api_health_pause_status', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function status(HealthPauseService $service): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        return $this->json($service->getStatus($user), 200);
    }
}
