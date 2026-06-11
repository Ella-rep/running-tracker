<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Tests\Factory\HealthPauseFactory;

final class DashboardAdvicePauseTest extends ApiTestCase
{
    private function makeFactory(): HealthPauseFactory
    {
        return new HealthPauseFactory($this->entityManager);
    }

    public function testAdviceReturnsSingleRestCardWhenPauseActive(): void
    {
        $secret = 'DaPause1_' . bin2hex(random_bytes(4));
        $user = $this->createUserFixture(plainPassword: $secret);
        $jwt = $this->loginAndGetJwt((string) $user->getEmail(), $secret);

        $this->makeFactory()->createActive($user);

        $this->client->request('GET', '/api/dashboard/advice', server: $this->authHeaders($jwt));

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $items = $payload['items'] ?? [];

        self::assertCount(1, $items, 'Exactly one advice card when pause is active');
        self::assertSame('rest', $items[0]['tone']);
        self::assertSame('Pause', $items[0]['badge']);
    }

    public function testAdviceReturnsNormalMultiCardWhenNoPause(): void
    {
        $secret = 'DaPause2_' . bin2hex(random_bytes(4));
        $user = $this->createUserFixture(plainPassword: $secret);
        $jwt = $this->loginAndGetJwt((string) $user->getEmail(), $secret);

        $this->client->request('GET', '/api/dashboard/advice', server: $this->authHeaders($jwt));

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $items = $payload['items'] ?? [];

        self::assertGreaterThanOrEqual(1, count($items));
        $tones = array_column($items, 'tone');
        self::assertNotContains('rest', $tones, 'No rest card when no pause active');
    }

    public function testAdviceReturnsRecoveryCardWhenInRecoveryWindow(): void
    {
        $secret = 'DaPause3_' . bin2hex(random_bytes(4));
        $user = $this->createUserFixture(plainPassword: $secret);
        $jwt = $this->loginAndGetJwt((string) $user->getEmail(), $secret);

        $resumedAt = (new \DateTimeImmutable('today'))->modify('-3 days')->format('Y-m-d');
        $this->makeFactory()->createResumed($user, $resumedAt);

        $this->client->request('GET', '/api/dashboard/advice', server: $this->authHeaders($jwt));

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $items = $payload['items'] ?? [];

        self::assertCount(1, $items);
        self::assertSame('recovery', $items[0]['tone']);
        self::assertSame('Reprise', $items[0]['badge']);
    }
}
