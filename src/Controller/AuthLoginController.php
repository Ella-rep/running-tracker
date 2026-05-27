<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles login API requests and delegates authentication to a service.
 */
final class AuthLoginController extends AbstractController
{
    /**
     * Authenticates a user and returns a JWT token.
     *
     * Uses a generic 401 response on credential mismatch to avoid
     * leaking whether an email exists in the system.
     */
    public function __invoke(
        Request $request,
        \App\Service\AuthLoginService $authLoginService,
    ): JsonResponse {
        try {
            $data = json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR);
            $email = strtolower(trim((string) ($data['email'] ?? '')));
            $password = (string) ($data['password'] ?? '');
            $rememberMe = filter_var($data['rememberMe'] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if (!is_bool($rememberMe)) {
                $rememberMe = true;
            }
            $result = $authLoginService->authenticate($email, $password);
            $response = $this->json($result['payload'], $result['status']);

            if ($result['status'] === 200 && isset($result['payload']['token'])) {
                $token = trim((string) $result['payload']['token']);
                if ($token !== '') {
                    $response->headers->setCookie(new Cookie(
                        'BEARER',
                        $token,
                        $rememberMe ? new \DateTimeImmutable('+7 days') : 0,
                        '/',
                        null,
                        $request->isSecure(),
                        false,
                        false,
                        Cookie::SAMESITE_LAX
                    ));
                }
            }

            return $response;
        } catch (\JsonException) {
            return $this->json([
                'code' => 'invalid_payload',
                'message' => 'Requete de connexion invalide.',
            ], 400);
        }
    }
}
