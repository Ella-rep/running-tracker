<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

/**
 * Integration coverage for protected plans API endpoints.
 */
final class PlansApiTest extends ApiTestCase
{
    /**
     * Creates and fetches plans through authenticated API calls.
     */
    public function testAuthenticatedUserCanCreateAndListPlans(): void
    {
        $password = 'PlanPass123!';
        $user = $this->createUserFixture(plainPassword: $password);
        $jwt = $this->loginAndGetJwt((string) $user->getEmail(), $password);

        $this->client->request(
            'POST',
            '/api/plans',
            server: $this->authHeaders($jwt),
            content: json_encode(['name' => 'Starter'], JSON_THROW_ON_ERROR)
        );

        self::assertResponseStatusCodeSame(201);

        $created = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Starter', $created['name'] ?? null);

        $this->client->request('GET', '/api/plans', server: $this->authHeaders($jwt));

        self::assertResponseIsSuccessful();

        $listed = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($listed);

        $members = $listed['hydra:member'] ?? $listed['items'] ?? $listed;
        self::assertIsArray($members);
        self::assertNotEmpty($members);
    }
}
