<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Race;

/**
 * Integration coverage for the dashboard races table:
 * DNS/DNF and past races are excluded from "upcoming", and the
 * status label uses human words (Aujourd'hui / Demain / Dans N ... / Passé).
 */
final class DashboardMetricsRacesApiTest extends ApiTestCase
{
    public function testRacesTableExcludesDnsAndUsesWordLabels(): void
    {
        $authSecret = 'MetricsAuth_' . bin2hex(random_bytes(6));
        $user = $this->createUserFixture(plainPassword: $authSecret);
        $jwt = $this->loginAndGetJwt((string) $user->getEmail(), $authSecret);

        $today = new \DateTimeImmutable('today');
        $make = function (string $name, string $date, ?string $dnf = null) use ($user): void {
            $race = (new Race())
                ->setUser($user)
                ->setName($name)
                ->setDate($date)
                ->setDistance('10km');
            if ($dnf !== null) {
                $race->setDnfStatus($dnf);
            }
            $this->entityManager->persist($race);
        };

        $make('RT Demain', $today->modify('+1 day')->format('Y-m-d'));
        $make('RT DNS', $today->modify('+5 days')->format('Y-m-d'), 'dns');
        $make('RT Passe', $today->modify('-3 days')->format('Y-m-d'));
        $this->entityManager->flush();

        $this->client->request('GET', '/api/dashboard/metrics', server: $this->authHeaders($jwt));
        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload['racesTable'] ?? null);

        $rows = [];
        foreach ($payload['racesTable'] as $row) {
            $rows[(string) ($row['name'] ?? '')] = $row;
        }

        self::assertArrayHasKey('RT Demain', $rows);
        self::assertTrue($rows['RT Demain']['upcoming']);
        self::assertSame('Demain', $rows['RT Demain']['statusLabel']);

        self::assertArrayHasKey('RT DNS', $rows);
        self::assertFalse($rows['RT DNS']['upcoming'], 'A DNS race must not be flagged upcoming.');
        self::assertSame('DNS', $rows['RT DNS']['statusLabel']);

        self::assertArrayHasKey('RT Passe', $rows);
        self::assertFalse($rows['RT Passe']['upcoming'], 'A past race without result must not be upcoming.');
        self::assertSame('Passé', $rows['RT Passe']['statusLabel']);
    }
}
