<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
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
        private readonly LoggerInterface $logger,
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
            new UserBadge($accessToken->getToken(), fn () => $this->resolveUserFromGoogleToken($client, $accessToken))
        );
    }

    private function resolveUserFromGoogleToken(object $client, object $accessToken): User
    {
        /** @var \League\OAuth2\Client\Provider\GoogleUser $googleUser */
        $googleUser = $client->fetchUserFromToken($accessToken);

        $googleId = $googleUser->getId();
        $email    = strtolower(trim($googleUser->getEmail() ?? ''));

        $user = $this->userRepository->findOneBy(['googleId' => $googleId]);
        if ($user !== null) {
            $this->logger->info('Google OAuth login: matched existing link by googleId.', [
                'userId' => $user->getId(),
                'email' => $user->getEmail(),
            ]);

            return $user;
        }

        if ($email !== '') {
            $user = $this->userRepository->findOneByEmailInsensitive($email);
            if ($user !== null && $user->getGoogleId() !== null && $user->getGoogleId() !== $googleId) {
                $this->logger->warning('Google OAuth link refused: email already linked to another googleId.', [
                    'userId' => $user->getId(),
                    'email' => $user->getEmail(),
                ]);

                throw new CustomUserMessageAuthenticationException('Ce compte email est deja associe a un autre compte Google.');
            }

            if ($user !== null) {
                $this->logger->info('Google OAuth login: linking existing account by email.', [
                    'userId' => $user->getId(),
                    'email' => $user->getEmail(),
                ]);
            }
        }

        if (!isset($user) || $user === null) {
            $user = new User();
            $username = $this->buildUniqueUsername($googleUser->getName() ?? $email);
            $user->setUsername($username);
            $user->setEmail($email ?: null);
            $user->setPassword(''); // no password for OAuth users

            $this->logger->info('Google OAuth login: creating new user account.', [
                'username' => $username,
                'email' => $email !== '' ? $email : null,
            ]);
        }

        $user->setGoogleId($googleId);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
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
        $oauthError = trim((string) $request->query->get('error', ''));
        $oauthErrorDescription = trim((string) $request->query->get('error_description', ''));

        if ($oauthError !== '' || $oauthErrorDescription !== '') {
            $details = $oauthError;
            if ($oauthErrorDescription !== '') {
                $details = $details !== '' ? $details . ': ' . $oauthErrorDescription : $oauthErrorDescription;
            }

            $message = 'OAuth Google: ' . $details;
        }

        $this->logger->warning('Google OAuth authentication failure.', [
            'route' => $request->attributes->get('_route'),
            'message' => $message,
            'oauth_error' => $oauthError !== '' ? $oauthError : null,
            'oauth_error_description' => $oauthErrorDescription !== '' ? $oauthErrorDescription : null,
        ]);

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
