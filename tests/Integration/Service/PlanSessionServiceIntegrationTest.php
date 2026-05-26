<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Plan;
use App\Service\PlanSessionService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for PlanSessionService wiring in Symfony container.
 */
final class PlanSessionServiceIntegrationTest extends KernelTestCase
{
    /**
     * Verifies service is registered and callable from Symfony container.
     */
    public function testServiceIsAvailableInContainer(): void
    {
        self::bootKernel();

        $service = static::getContainer()->get(PlanSessionService::class);
        $sessions = $service->getSessionsForPlan((new Plan())->setName('starter'));

        self::assertInstanceOf(PlanSessionService::class, $service);
        self::assertCount(4, $sessions);
    }
}
