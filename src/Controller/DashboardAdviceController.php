<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\DashboardAdviceService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Exposes contextual dashboard advice API endpoint.
 */
final class DashboardAdviceController extends AbstractController
{
    /**
     * Returns personalized advice items for the authenticated user.
     * If a city query param is provided, it is saved to the user profile
     * so the preference persists across devices and sessions.
     */
    #[Route('/api/dashboard/advice', name: 'api_dashboard_advice', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(
        DashboardAdviceService $service,
        Request $request,
        EntityManagerInterface $em,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['items' => []], 401);
        }

        $city = trim((string) $request->query->get('city', ''));

        if ($city !== '') {
            // Persist city preference when it changes, for cross-device continuity.
            if ($user->getMeteoCity() !== $city) {
                $user->setMeteoCity($city);
                $em->flush();
            }
        } elseif ($user->getMeteoCity() !== null) {
            // No city in request — fall back to the user's saved preference.
            $city = $user->getMeteoCity();
        }

        return $this->json([
            'items' => $service->build($user, $city !== '' ? $city : null),
        ]);
    }
}
