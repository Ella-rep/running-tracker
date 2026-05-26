<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

/**
 * Integration coverage for auth endpoints.
 */
final class AuthApiTest extends ApiTestCase
{
    /**
     * Covers register, login and authenticated me endpoint.
     */
    public function testRegisterLoginAndMeFlow(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $username = 'api_user_' . $suffix;
        $email = 'api_user_' . $suffix . '@example.test';
        $password = 'ApiPass123!';

        $this->client->request(
            'POST',
            '/api/auth/register',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: json_encode([
                'username' => $username,
                'email' => $email,
                'plainPassword' => $password,
            ], JSON_THROW_ON_ERROR)
        );

        self::assertResponseStatusCodeSame(201);

        $jwt = $this->loginAndGetJwt($email, $password);

        $this->client->request('GET', '/api/auth/me', server: $this->authHeaders($jwt));

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($email, $payload['email'] ?? null);
        self::assertSame($username, $payload['username'] ?? null);
    }

    /**
     * Denies access on protected me endpoint without token.
     */
    public function testMeEndpointRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/auth/me', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(401);
    }
}
