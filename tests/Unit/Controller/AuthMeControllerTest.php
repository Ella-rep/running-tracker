<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\AuthMeController;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Unit tests for AuthMeController.
 */
final class AuthMeControllerTest extends TestCase
{
    /**
     * Returns authenticated user when token storage contains a User instance.
     */
    public function testInvokeReturnsAuthenticatedUser(): void
    {
        $user = (new User())
            ->setUsername('alice')
            ->setEmail('alice@example.test')
            ->setPassword('hashed');

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);

        $controller = new AuthMeController();
        $controller->setContainer($this->buildContainerWithTokenStorage($tokenStorage));

        $result = $controller->__invoke();

        self::assertSame($user, $result);
    }

    /**
     * Throws 403 when token storage does not provide an authenticated user.
     */
    public function testInvokeThrowsAccessDeniedWhenUserIsMissing(): void
    {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);

        $controller = new AuthMeController();
        $controller->setContainer($this->buildContainerWithTokenStorage($tokenStorage));

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Utilisateur non authentifie.');

        $controller->__invoke();
    }

    private function buildContainerWithTokenStorage(TokenStorageInterface $tokenStorage): Container
    {
        $container = new Container();
        $container->set('security.token_storage', $tokenStorage);

        return $container;
    }
}
