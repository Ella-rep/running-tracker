<?php

namespace App\Controller;

use App\Service\PasswordResetService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Exposes password reset request and confirmation API endpoints.
 */
final class PasswordResetController extends AbstractController
{
    /**
     * Starts password reset flow for the provided email.
     */
    #[Route('/api/auth/reset-password/request', name: 'api_auth_reset_password_request', methods: ['POST'])]
    public function requestReset(
        Request $request,
        PasswordResetService $passwordResetService,
    ): JsonResponse {
        $result = $passwordResetService->requestReset($request->getContent(), $request->getSchemeAndHttpHost());
        return $this->json($result['payload'], $result['status']);
    }

    /**
     * Confirms password reset with token and new password.
     */
    #[Route('/api/auth/reset-password/confirm', name: 'api_auth_reset_password_confirm', methods: ['POST'])]
    public function confirmReset(
        Request $request,
        PasswordResetService $passwordResetService,
    ): JsonResponse {
        $result = $passwordResetService->confirmReset($request->getContent());
        return $this->json($result['payload'], $result['status']);
    }
}

