<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

final class GoogleAuthenticator extends OAuth2Authenticator
{
    public function __construct(
        private readonly ClientRegistry $clientRegistry,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $em,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly RouterInterface $router,
    ) {}

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'connect_google_check';
    }

    public function authenticate(Request $request): Passport
    {
        $client      = $this->clientRegistry->getClient('google');
        $accessToken = $this->fetchAccessToken($client);

        return new SelfValidatingPassport(
            new UserBadge($accessToken->getToken(), function () use ($accessToken, $client) {
                /** @var \League\OAuth2\Client\Provider\GoogleUser $googleUser */
                $googleUser = $client->fetchUserFromToken($accessToken);

                $googleId = $googleUser->getId();
                $email    = strtolower(trim($googleUser->getEmail() ?? ''));

                // 1. Already linked by googleId
                $user = $this->userRepository->findOneBy(['googleId' => $googleId]);
                if ($user !== null) {
                    return $user;
                }

                // 2. Existing account with same email → link it
                if ($email !== '') {
                    $user = $this->userRepository->findOneBy(['email' => $email]);
                }

                // 3. New user
                if ($user === null) {
                    $user = new User();
                    $username = $this->buildUniqueUsername($googleUser->getName() ?? $email);
                    $user->setUsername($username);
                    $user->setEmail($email ?: null);
                    $user->setPassword(''); // no password for OAuth users
                }

                $user->setGoogleId($googleId);
                $this->em->persist($user);
                $this->em->flush();

                return $user;
            })
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        /** @var User $user */
        $user = $token->getUser();
        $jwt  = $this->jwtManager->create($user);

        return new RedirectResponse('/login?google_token=' . urlencode($jwt));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $message = strtr($exception->getMessageKey(), $exception->getMessageData());

        return new RedirectResponse('/login?google_error=' . urlencode($message));
    }

    private function buildUniqueUsername(string $base): string
    {
        // Slugify: keep only alphanumeric + underscore, max 50 chars
        $slug = preg_replace('/[^a-z0-9_]/i', '_', $base);
        $slug = substr(trim($slug, '_'), 0, 50);
        $slug = $slug !== '' ? $slug : 'user';

        if ($this->userRepository->findOneBy(['username' => $slug]) === null) {
            return $slug;
        }

        // Append random suffix until unique
        do {
            $candidate = $slug . '_' . random_int(1000, 9999);
        } while ($this->userRepository->findOneBy(['username' => $candidate]) !== null);

        return $candidate;
    }
}
