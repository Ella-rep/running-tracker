<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * User profile page: account info, password, photo and email preferences.
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ProfileController extends AbstractController
{
    private const ALLOWED_PHOTO_MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    private const MAX_PHOTO_BYTES = 4 * 1024 * 1024;

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

    /**
     * Changes the user's password after verifying the current one.
     */
    #[Route('/profile/password', name: 'app_profile_password', methods: ['POST'])]
    public function changePassword(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
    ): RedirectResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Utilisateur non authentifié.');
        }

        if (!$this->isCsrfTokenValid('profile_password', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide, merci de réessayer.');

            return $this->redirectToRoute('app_profile');
        }

        $current = (string) $request->request->get('current_password');
        $new = (string) $request->request->get('new_password');
        $confirm = (string) $request->request->get('new_password_confirm');

        if (!$passwordHasher->isPasswordValid($user, $current)) {
            $this->addFlash('error', 'Mot de passe actuel incorrect.');

            return $this->redirectToRoute('app_profile');
        }

        if (mb_strlen($new) < 6) {
            $this->addFlash('error', 'Le nouveau mot de passe doit faire au moins 6 caractères.');

            return $this->redirectToRoute('app_profile');
        }

        if ($new !== $confirm) {
            $this->addFlash('error', 'La confirmation ne correspond pas au nouveau mot de passe.');

            return $this->redirectToRoute('app_profile');
        }

        $user->setPassword($passwordHasher->hashPassword($user, $new));
        $entityManager->flush();

        $this->addFlash('success', 'Mot de passe mis à jour.');

        return $this->redirectToRoute('app_profile');
    }

    /**
     * Uploads (or replaces) the optional profile picture.
     */
    #[Route('/profile/photo', name: 'app_profile_photo', methods: ['POST'])]
    public function uploadPhoto(Request $request, EntityManagerInterface $entityManager): RedirectResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Utilisateur non authentifié.');
        }

        if (!$this->isCsrfTokenValid('profile_photo', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide, merci de réessayer.');

            return $this->redirectToRoute('app_profile');
        }

        $file = $request->files->get('photo');
        if (!$file instanceof UploadedFile) {
            $this->addFlash('error', 'Aucun fichier reçu.');

            return $this->redirectToRoute('app_profile');
        }

        if ($file->getSize() > self::MAX_PHOTO_BYTES) {
            $this->addFlash('error', 'Image trop lourde (4 Mo max).');

            return $this->redirectToRoute('app_profile');
        }

        if (!in_array($file->getMimeType(), self::ALLOWED_PHOTO_MIME, true)) {
            $this->addFlash('error', 'Format non supporté (JPEG, PNG, WEBP ou GIF).');

            return $this->redirectToRoute('app_profile');
        }

        $dir = $this->getParameter('kernel.project_dir') . '/public/uploads/avatars';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->addFlash('error', "Impossible de préparer le dossier d'upload.");

            return $this->redirectToRoute('app_profile');
        }

        $ext = $file->guessExtension() ?: 'bin';
        $filename = 'avatar_' . $user->getId() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

        try {
            $previous = $user->getPhotoFilename();
            $file->move($dir, $filename);
            if ($previous !== null && $previous !== '' && is_file($dir . '/' . $previous)) {
                @unlink($dir . '/' . $previous);
            }
        } catch (FileException) {
            $this->addFlash('error', "Échec de l'enregistrement de l'image.");

            return $this->redirectToRoute('app_profile');
        }

        $user->setPhotoFilename($filename);
        $entityManager->flush();

        $this->addFlash('success', 'Photo de profil mise à jour.');

        return $this->redirectToRoute('app_profile');
    }

    /**
     * Removes the profile picture (optional, GDPR friendly).
     */
    #[Route('/profile/photo/delete', name: 'app_profile_photo_delete', methods: ['POST'])]
    public function deletePhoto(Request $request, EntityManagerInterface $entityManager): RedirectResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Utilisateur non authentifié.');
        }

        if (!$this->isCsrfTokenValid('profile_photo_delete', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide, merci de réessayer.');

            return $this->redirectToRoute('app_profile');
        }

        $filename = $user->getPhotoFilename();
        if ($filename !== null && $filename !== '') {
            $path = $this->getParameter('kernel.project_dir') . '/public/uploads/avatars/' . $filename;
            if (is_file($path)) {
                @unlink($path);
            }
            $user->setPhotoFilename(null);
            $entityManager->flush();
        }

        $this->addFlash('success', 'Photo de profil supprimée.');

        return $this->redirectToRoute('app_profile');
    }
}
