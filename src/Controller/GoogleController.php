<?php

namespace App\Controller;

use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Handles Google OAuth entrypoint and callback routes.
 */
final class GoogleController extends AbstractController
{
    public function __construct(
        #[Autowire('%env(default::GOOGLE_REDIRECT_URI)%')]
        private readonly string $googleRedirectUri,
    ) {}

    /**
     * Starts Google OAuth by redirecting to Google's authorization page.
     */
    #[Route('/connect/google', name: 'connect_google')]
    public function connectAction(ClientRegistry $clientRegistry): RedirectResponse
    {
        $options = [
            'prompt' => 'select_account',
        ];

        $configuredRedirectUri = trim($this->googleRedirectUri);
        if ($configuredRedirectUri !== '') {
            $options['redirect_uri'] = $configuredRedirectUri;
        }

        return $clientRegistry
            ->getClient('google')
            ->redirect(['openid', 'email', 'profile'], $options);
    }

    /**
     * The Google OAuth callback URL — handled entirely by GoogleAuthenticator.
     * This method will never be reached; the authenticator intercepts the request first.
     */
    #[Route('/connect/google/check', name: 'connect_google_check')]
    public function connectCheckAction(): never
    {
        throw new \LogicException('This action is handled by GoogleAuthenticator.');
    }
}
