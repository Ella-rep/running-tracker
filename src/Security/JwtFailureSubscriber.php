<?php

declare(strict_types=1);

namespace App\Security;

use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationFailureEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Handles invalid/expired JWT failures by clearing auth cookie and redirecting web users to login.
 */
final class JwtFailureSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            Events::JWT_INVALID => 'onJwtFailure',
            Events::JWT_EXPIRED => 'onJwtFailure',
        ];
    }

    public function onJwtFailure(AuthenticationFailureEvent $event): void
    {
        $request = $event->getRequest();
        if ($request === null) {
            return;
        }

        if ($this->isApiRequest((string) $request->getPathInfo())) {
            $response = $event->getResponse() ?? new JsonResponse(['message' => 'JWT invalid token'], 401);
            $this->clearBearerCookie($response);
            $event->setResponse($response);
            return;
        }

        $params = [];
        $next = $this->resolveSafeNextPath((string) $request->getRequestUri(), (string) $request->getPathInfo());
        if ($next !== null) {
            $params['next'] = $next;
        }

        $response = new RedirectResponse($this->urlGenerator->generate('app_login', $params));
        $this->clearBearerCookie($response);
        $event->setResponse($response);
    }

    private function isApiRequest(string $path): bool
    {
        return str_starts_with($path, '/api');
    }

    private function resolveSafeNextPath(string $requestUri, string $path): ?string
    {
        if ($path === '' || $path === '/login' || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return null;
        }

        if ($requestUri === '' || !str_starts_with($requestUri, '/') || str_starts_with($requestUri, '//')) {
            return $path;
        }

        return str_starts_with($requestUri, '/login') ? null : $requestUri;
    }

    private function clearBearerCookie(Response $response): void
    {
        $response->headers->clearCookie('BEARER', '/');
        $response->headers->clearCookie('BEARER', '/', null, true, true, 'lax');
    }
}
