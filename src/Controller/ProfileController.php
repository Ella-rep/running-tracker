<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * User profile page: account info and email preferences (weekly recap opt-in).
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ProfileController extends AbstractController
{
    /**
     * Displays the profile page with account info and preferences.
     */
    #[Route('/profile', name: 'app_profile', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();

        return $this->render('profile/index.html.twig', [
            'username' => $user?->getUserIdentifier(),
            'profileUser' => $user,
        ]);
    }

    /**
     * Toggles the weekly recap email opt-in (GDPR unsubscribe) for the user.
     */
    #[Route('/profile/email-hebdo', name: 'app_profile_email_hebdo', methods: ['POST'])]
    public function toggleEmailHebdo(Request $request, EntityManagerInterface $entityManager): RedirectResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Utilisateur non authentifié.');
        }

        if (!$this->isCsrfTokenValid('profile_email_hebdo', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide, merci de réessayer.');

            return $this->redirectToRoute('app_profile');
        }

        $subscribe = $request->request->getBoolean('email_hebdo');
        $user->setEmailHebdo($subscribe);
        $entityManager->flush();

        $this->addFlash(
            'success',
            $subscribe
                ? 'Tu es inscrit·e au résumé hebdo.'
                : 'Tu es désabonné·e du résumé hebdo.'
        );

        return $this->redirectToRoute('app_profile');
    }
}
