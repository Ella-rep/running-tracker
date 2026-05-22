<?php

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Returns the currently authenticated user resource.
 */
final class AuthMeController extends AbstractController
{
    /**
     * Resolves and returns the authenticated user or throws 403.
     */
    public function __invoke(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Utilisateur non authentifie.');
        }

        return $user;
    }
}
