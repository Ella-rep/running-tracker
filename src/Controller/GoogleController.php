<?php

namespace App\Controller;

use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

final class GoogleController extends AbstractController
{
    #[Route('/connect/google', name: 'connect_google')]
    public function connectAction(ClientRegistry $clientRegistry): RedirectResponse
    {
        return $clientRegistry
            ->getClient('google')
            ->redirect(['email', 'profile'], []);
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
