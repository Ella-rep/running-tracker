<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

/**
 * Integration coverage for dashboard advice endpoint.
 */
final class DashboardAdviceApiTest extends ApiTestCase
{
    /**
     * Returns weather advice using default city Paris when no city is provided.
     */
    public function testDashboardAdviceUsesParisByDefaultWhenNoCityQuery(): void
    {
        $authSecret = 'AdviceAuth_' . bin2hex(random_bytes(6));
        $user = $this->createUserFixture(plainPassword: $authSecret);
        $jwt = $this->loginAndGetJwt((string) $user->getEmail(), $authSecret);

        $this->client->request('GET', '/api/dashboard/advice', server: $this->authHeaders($jwt));

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsArray($payload['items'] ?? null);
        self::assertNotEmpty($payload['items']);

        $weather = null;
        foreach ($payload['items'] as $item) {
            $title = strtolower((string) ($item['title'] ?? ''));
            if (str_contains($title, 'meteo')) {
                $weather = $item;
                break;
            }
        }

        self::assertIsArray($weather);
        self::assertSame('Paris', $weather['appliedCity'] ?? null);
        self::assertSame('default', $weather['cityStatus'] ?? null);
        self::assertSame(true, $weather['cityApplied'] ?? null);
    }
}

