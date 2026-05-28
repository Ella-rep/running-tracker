<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\AuthLoginController;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AuthLoginService;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Unit tests for AuthLoginController.
 */
final class AuthLoginControllerTest extends TestCase
{
    /**
     * Returns 400 when request payload is not valid JSON.
     */
    public function testInvokeReturnsBadRequestForInvalidJson(): void
    {
        $controller = new AuthLoginController();
        $service = $this->buildAuthService();

        $request = new Request(content: '{invalid-json');

        $response = $controller->__invoke($request, $service);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('invalid_payload', (string) $response->getContent());
    }

    /**
     * Sets BEARER cookie with Secure flag when request is behind HTTPS proxy.
     */
    public function testInvokeSetsSecureBearerCookieBehindHttpsProxy(): void
    {
        $controller = new AuthLoginController();

        $user = (new User())
            ->setUsername('alice')
            ->setEmail('alice@example.test')
            ->setPassword('hashed');

        $users = $this->createMock(UserRepository::class);
        $users
            ->expects(self::once())
            ->method('findOneBy')
            ->with(['email' => 'alice@example.test'])
            ->willReturn($user);

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher
            ->expects(self::once())
            ->method('isPasswordValid')
            ->with($user, 'secret')
            ->willReturn(true);

        $jwtManager = $this->createMock(JWTTokenManagerInterface::class);
        $jwtManager
            ->expects(self::once())
            ->method('create')
            ->with($user)
            ->willReturn('jwt-value');

        $service = $this->buildAuthService($users, $hasher, $jwtManager);

        $request = new Request(
            content: json_encode([
                'email' => 'Alice@Example.Test',
                'password' => 'secret',
                'rememberMe' => true,
            ], JSON_THROW_ON_ERROR)
        );
        $request->headers->set('x-forwarded-proto', 'https');

        $response = $controller->__invoke($request, $service);

        self::assertSame(200, $response->getStatusCode());
        $cookies = $response->headers->getCookies();
        self::assertCount(1, $cookies);
        self::assertSame('BEARER', $cookies[0]->getName());
        self::assertSame('jwt-value', $cookies[0]->getValue());
        self::assertTrue($cookies[0]->isSecure());
    }

    /**
     * Does not set BEARER cookie on authentication failure response.
     */
    public function testInvokeDoesNotSetCookieWhenAuthenticationFails(): void
    {
        $controller = new AuthLoginController();

        $users = $this->createMock(UserRepository::class);
        $users
            ->expects(self::once())
            ->method('findOneBy')
            ->with(['email' => 'alice@example.test'])
            ->willReturn(null);

        $service = $this->buildAuthService($users);

        $request = new Request(content: json_encode([
            'email' => 'alice@example.test',
            'password' => 'bad',
            'rememberMe' => true,
        ], JSON_THROW_ON_ERROR));

        $response = $controller->__invoke($request, $service);

        self::assertSame(401, $response->getStatusCode());
        self::assertCount(0, $response->headers->getCookies());
    }

    private function buildAuthService(
        ?UserRepository $users = null,
        ?UserPasswordHasherInterface $hasher = null,
        ?JWTTokenManagerInterface $jwtManager = null,
        ?LoggerInterface $logger = null
    ): AuthLoginService {
        return new AuthLoginService(
            $users ?? $this->createMock(UserRepository::class),
            $hasher ?? $this->createMock(UserPasswordHasherInterface::class),
            $jwtManager ?? $this->createMock(JWTTokenManagerInterface::class),
            $logger ?? $this->createMock(LoggerInterface::class),
        );
    }
}

