<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Tests\Factory\HealthPauseFactory;

final class DashboardMetricsPauseTest extends ApiTestCase
{
    private function makeFactory(): HealthPauseFactory
    {
        return new HealthPauseFactory($this->entityManager);
    }

    public function testPauseStatusActiveInMetricsPayloadWhenPauseActive(): void
    {
        $secret = 'DmPause1_' . bin2hex(random_bytes(4));
        $user = $this->createUserFixture(plainPassword: $secret);
        $jwt = $this->loginAndGetJwt((string) $user->getEmail(), $secret);

        $this->makeFactory()->createActive($user);

        $this->client->request('GET', '/api/dashboard/metrics', server: $this->authHeaders($jwt));

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($payload['pauseStatus']['active']);
        self::assertSame('paused', $payload['trainingLoad']['statusKey']);
        self::assertSame([], $payload['coherenceAlerts']);
    }

    public function testPlanProgressPausedTrueWhenPauseActive(): void
    {
        $secret = 'DmPause2_' . bin2hex(random_bytes(4));
        $user = $this->createUserFixture(plainPassword: $secret);
        $jwt = $this->loginAndGetJwt((string) $user->getEmail(), $secret);

        $this->makeFactory()->createActive($user);

        $this->client->request('GET', '/api/dashboard/metrics', server: $this->authHeaders($jwt));

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $plans = $payload['planProgress']['plans'] ?? [];
        foreach ($plans as $plan) {
            self::assertTrue($plan['paused'] ?? false, 'All plans must have paused:true when pause is active');
        }
    }

    public function testPauseStatusFalseInMetricsWhenNoPause(): void
    {
        $secret = 'DmPause3_' . bin2hex(random_bytes(4));
        $user = $this->createUserFixture(plainPassword: $secret);
        $jwt = $this->loginAndGetJwt((string) $user->getEmail(), $secret);

        $this->client->request('GET', '/api/dashboard/metrics', server: $this->authHeaders($jwt));

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertFalse($payload['pauseStatus']['active'] ?? true);
        self::assertNotSame('paused', $payload['trainingLoad']['statusKey'] ?? '');
    }
}
