<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\AuthLoginController;
use App\Service\AuthLoginService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

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
        $service = $this->createMock(AuthLoginService::class);

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

        $service = $this->createMock(AuthLoginService::class);
        $service
            ->expects(self::once())
            ->method('authenticate')
            ->with('alice@example.test', 'secret')
            ->willReturn([
                'status' => 200,
                'payload' => ['token' => 'jwt-value'],
            ]);

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

        $service = $this->createMock(AuthLoginService::class);
        $service
            ->expects(self::once())
            ->method('authenticate')
            ->willReturn([
                'status' => 401,
                'payload' => [
                    'code' => 'invalid_credentials',
                    'message' => 'Identifiants invalides.',
                ],
            ]);

        $request = new Request(content: json_encode([
            'email' => 'alice@example.test',
            'password' => 'bad',
            'rememberMe' => true,
        ], JSON_THROW_ON_ERROR));

        $response = $controller->__invoke($request, $service);

        self::assertSame(401, $response->getStatusCode());
        self::assertCount(0, $response->headers->getCookies());
    }
}

