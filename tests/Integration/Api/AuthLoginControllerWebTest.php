<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

/**
 * Integration tests focused on AuthLoginController HTTP contract.
 */
final class AuthLoginControllerWebTest extends ApiTestCase
{
    private const LOGIN_PATH = '/api/auth/login';

    /** @var array<string, string> */
    private const JSON_HEADERS = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ];

    /**
     * Returns normalized invalid payload error when JSON is malformed.
     */
    public function testLoginReturnsInvalidPayloadOnMalformedJson(): void
    {
        $this->client->request(
            'POST',
            self::LOGIN_PATH,
            server: self::JSON_HEADERS,
            content: '{invalid-json'
        );

        self::assertResponseStatusCodeSame(400);

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('invalid_payload', $payload['code'] ?? null);
    }

    /**
     * Returns 401 and no BEARER cookie on invalid credentials.
     */
    public function testLoginInvalidCredentialsDoesNotSetBearerCookie(): void
    {
        $this->client->request(
            'POST',
            self::LOGIN_PATH,
            server: self::JSON_HEADERS,
            content: json_encode([
                'email' => 'nobody@example.test',
                'password' => 'bad-password',
            ], JSON_THROW_ON_ERROR)
        );

        self::assertResponseStatusCodeSame(401);
        self::assertNull($this->findBearerCookieValue());
    }

    /**
     * Returns token and sets BEARER cookie on valid credentials.
     */
    public function testLoginSuccessSetsBearerCookieAndReturnsToken(): void
    {
        $plainSecret = 'Test-' . bin2hex(random_bytes(4));
        $user = $this->createUserFixture(
            username: 'login_cookie_user',
            email: 'login-cookie-user@example.test',
            plainPassword: $plainSecret
        );

        $this->client->request(
            'POST',
            self::LOGIN_PATH,
            server: self::JSON_HEADERS,
            content: json_encode([
                'email' => (string) $user->getEmail(),
                'password' => $plainSecret,
                'rememberMe' => true,
            ], JSON_THROW_ON_ERROR)
        );

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsString($payload['token'] ?? null);
        self::assertNotSame('', trim((string) $payload['token']));

        self::assertSame((string) $payload['token'], $this->findBearerCookieValue());
    }

    private function findBearerCookieValue(): ?string
    {
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'BEARER') {
                return $cookie->getValue();
            }
        }

        return null;
    }
}
