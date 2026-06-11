<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Tests\Factory\HealthPauseFactory;

final class HealthPauseApiTest extends ApiTestCase
{
    private function makeFactory(): HealthPauseFactory
    {
        return new HealthPauseFactory($this->entityManager);
    }

    public function testActivateWithEmptyBodyReturns201WithActivePause(): void
    {
        $secret = 'HpActivate_' . bin2hex(random_bytes(4));
        $user = $this->createUserFixture(plainPassword: $secret);
        $jwt = $this->loginAndGetJwt((string) $user->getEmail(), $secret);

        $this->client->request('POST', '/api/health-pause/activate', server: $this->authHeaders($jwt), content: '{}');

        self::assertResponseStatusCodeSame(201);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($payload['active']);
        self::assertSame((new \DateTimeImmutable('today'))->format('Y-m-d'), $payload['startedAt']);
        self::assertNull($payload['type']);
    }

    public function testActivateWithTypeAndEstimatedDaysReturnsData(): void
    {
        $secret = 'HpActivate2_' . bin2hex(random_bytes(4));
        $user = $this->createUserFixture(plainPassword: $secret);
        $jwt = $this->loginAndGetJwt((string) $user->getEmail(), $secret);

        $this->client->request(
            'POST',
            '/api/health-pause/activate',
            server: $this->authHeaders($jwt),
            content: json_encode(['type' => 'injury', 'estimatedDays' => 14], JSON_THROW_ON_ERROR)
        );

        self::assertResponseStatusCodeSame(201);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($payload['active']);
        self::assertSame('injury', $payload['type']);
        self::assertSame(14, $payload['estimatedDays']);
    }

    public function testActivateWithoutJwtReturns401(): void
    {
        $this->client->request(
            'POST',
            '/api/health-pause/activate',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: '{}'
        );

        self::assertResponseStatusCodeSame(401);
    }

    public function testSecondActivateReplacesFirstRow(): void
    {
        $secret = 'HpReactivate_' . bin2hex(random_bytes(4));
        $user = $this->createUserFixture(plainPassword: $secret);
        $jwt = $this->loginAndGetJwt((string) $user->getEmail(), $secret);

        $this->client->request('POST', '/api/health-pause/activate', server: $this->authHeaders($jwt), content: '{}');
        self::assertResponseStatusCodeSame(201);

        $this->client->request(
            'POST',
            '/api/health-pause/activate',
            server: $this->authHeaders($jwt),
            content: json_encode(['type' => 'fatigue'], JSON_THROW_ON_ERROR)
        );

        self::assertResponseStatusCodeSame(201);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($payload['active']);
        self::assertSame('fatigue', $payload['type']);
    }

    public function testStatusWithNoPauseReturnsActiveFalseAndAllNulls(): void
    {
        $secret = 'HpStatus1_' . bin2hex(random_bytes(4));
        $user = $this->createUserFixture(plainPassword: $secret);
        $jwt = $this->loginAndGetJwt((string) $user->getEmail(), $secret);

        $this->client->request('GET', '/api/health-pause/status', server: $this->authHeaders($jwt));

        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($payload['active']);
        self::assertNull($payload['startedAt']);
        self::assertNull($payload['type']);
        self::assertNull($payload['estimatedDays']);
        self::assertNull($payload['resumedAt']);
    }

    public function testStatusWithActivePauseReturnsCorrectShape(): void
    {
        $secret = 'HpStatus2_' . bin2hex(random_bytes(4));
        $user = $this->createUserFixture(plainPassword: $secret);
        $jwt = $this->loginAndGetJwt((string) $user->getEmail(), $secret);

        $this->makeFactory()->createActive($user, 'illness', 7);

        $this->client->request('GET', '/api/health-pause/status', server: $this->authHeaders($jwt));

        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($payload['active']);
        self::assertSame((new \DateTimeImmutable('today'))->format('Y-m-d'), $payload['startedAt']);
        self::assertSame('illness', $payload['type']);
        self::assertSame(7, $payload['estimatedDays']);
    }

    public function testResumeWithActivePauseReturns200WithResumedAt(): void
    {
        $secret = 'HpResume1_' . bin2hex(random_bytes(4));
        $user = $this->createUserFixture(plainPassword: $secret);
        $jwt = $this->loginAndGetJwt((string) $user->getEmail(), $secret);

        $this->makeFactory()->createActive($user);

        $this->client->request('POST', '/api/health-pause/resume', server: $this->authHeaders($jwt), content: '{}');

        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($payload['active']);
        self::assertSame((new \DateTimeImmutable('today'))->format('Y-m-d'), $payload['resumedAt']);
        self::assertTrue($payload['recoveryWindowActive']);
        self::assertSame(7, $payload['recoveryDaysRemaining']);
    }

    public function testResumeWithNoActivePauseReturns200WithAllFalse(): void
    {
        $secret = 'HpResume2_' . bin2hex(random_bytes(4));
        $user = $this->createUserFixture(plainPassword: $secret);
        $jwt = $this->loginAndGetJwt((string) $user->getEmail(), $secret);

        $this->client->request('POST', '/api/health-pause/resume', server: $this->authHeaders($jwt), content: '{}');

        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($payload['active']);
        self::assertNull($payload['startedAt']);
    }

    public function testResumeWithoutJwtReturns401(): void
    {
        $this->client->request(
            'POST',
            '/api/health-pause/resume',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: '{}'
        );

        self::assertResponseStatusCodeSame(401);
    }
}
