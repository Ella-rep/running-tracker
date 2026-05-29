<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Redirects unauthenticated web requests to the login page.
 */
final class WebLoginEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        $next = $this->resolveSafeNextPath($request);
        $params = [];
        if ($next !== null) {
            $params['next'] = $next;
        }

        return new RedirectResponse($this->urlGenerator->generate('app_login', $params));
    }

    private function resolveSafeNextPath(Request $request): ?string
    {
        $path = (string) $request->getPathInfo();
        if ($path === '' || $path === '/login') {
            return null;
        }

        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return null;
        }

        $query = (string) $request->getQueryString();
        return $query !== '' ? ($path . '?' . $query) : $path;
    }
}
