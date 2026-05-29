<?php

namespace App\Controller;

use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use App\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;
use App\Security\GoogleAuthenticator;

/**
 * Handles Google OAuth entrypoint and callback routes.
 */
final class GoogleController extends AbstractController
{
    public function __construct(
        #[Autowire('%env(DEFAULT_URI)%')]
        private readonly string $defaultUri,
    ) {
    }

    /**
     * Starts Google OAuth by redirecting to Google's authorization page.
     */
    #[Route('/connect/google', name: 'connect_google')]
    public function connectAction(Request $request, ClientRegistry $clientRegistry): RedirectResponse
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_login');
        }

        $next = $this->resolveSafeNextPath((string) $request->query->get('next', ''));
        if ($next !== null && $request->hasSession()) {
            $request->getSession()->set('oauth_next', $next);
        }

        $callbackPath = $this->generateUrl('connect_google_check', [], UrlGeneratorInterface::ABSOLUTE_PATH);
        $baseUri = rtrim(trim($this->defaultUri), '/');
        if ($baseUri === '') {
            $baseUri = $request->getSchemeAndHttpHost();
        }

        $redirectUri = $baseUri . $callbackPath;

        return $clientRegistry
            ->getClient('google')
            ->redirect(
                ['openid', 'email', 'profile'],
                ['prompt' => 'select_account', 'redirect_uri' => $redirectUri]
            );
    }

    private function resolveSafeNextPath(string $next): ?string
    {
        $next = trim($next);
        if ($next === '' || !str_starts_with($next, '/') || str_starts_with($next, '//')) {
            return null;
        }

        if (str_starts_with($next, '/login')) {
            return null;
        }

        return $next;
    }

    /**
     * The Google OAuth callback URL — handled entirely by GoogleAuthenticator.
     * This method will never be reached; the authenticator intercepts the request first.
     */
    #[Route('/connect/google/check', name: 'connect_google_check')]
    public function connectCheckAction(
        Request $request,
        ClientRegistry $clientRegistry,
        UserPasswordHasherInterface $userPasswordHasher,
        EntityManagerInterface $entityManager,
        UserAuthenticatorInterface $userAuthenticator,
        GoogleAuthenticator $authenticator
    ) {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_login');
        }
        $client = $clientRegistry->getClient('google');
        try {
            /** @var \League\OAuth2\Client\Provider\GoogleUser $oauthUser */
            $oauthUser = $client->fetchUser();
            $email = strtolower(trim((string) ($oauthUser->getEmail() ?? '')));

            if ($email === '') {
                throw new \LogicException('Google OAuth n a pas retourne d email exploitable.');
            }

            $existingUser = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($existingUser) {
                return $userAuthenticator->authenticateUser($existingUser, $authenticator, $request);
            }

            $newUser = new User();
            $baseUsername = trim((string) ($oauthUser->getName() ?? ''));
            if ($baseUsername === '') {
                $baseUsername = explode('@', $email)[0] ?: 'user';
            }

            $username = preg_replace('/[^a-z0-9_]/i', '_', $baseUsername) ?: 'user';
            $username = substr(trim($username, '_'), 0, 64);
            if ($username === '') {
                $username = 'user';
            }

            $newUser->setUsername($username);
            $newUser->setPassword($userPasswordHasher->hashPassword($newUser, bin2hex(random_bytes(24))));
            $newUser->setEmail($email);

            $entityManager->persist($newUser);
            $entityManager->flush();

            return $userAuthenticator->authenticateUser($newUser, $authenticator, $request);
        } catch (\Exception $e) {
            throw new \LogicException('This action is handled by GoogleAuthenticator.');
        }
    }
}
