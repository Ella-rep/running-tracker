<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Plan;
use App\Service\PlanSessionService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PlanSessionService.
 */
final class PlanSessionServiceTest extends TestCase
{
    /**
     * Verifies that starter plan returns normalized starter sessions.
     */
    public function testGetSessionsForStarterPlanReturnsExpectedTemplate(): void
    {
        $plan = (new Plan())->setName('Starter');
        $service = new PlanSessionService();

        $sessions = $service->getSessionsForPlan($plan);

        self::assertCount(4, $sessions);
        self::assertSame('EF', $sessions[0]['sessionType']);
        self::assertSame("45' facile", $sessions[0]['format']);
        self::assertFalse($sessions[0]['isOptional']);
        self::assertTrue($sessions[2]['isOptional']);
    }

    /**
     * Verifies fallback behavior for unknown plan names.
     */
    public function testGetSessionsForUnknownPlanFallsBackToStarterTemplate(): void
    {
        $plan = (new Plan())->setName('Custom');
        $service = new PlanSessionService();

        $sessions = $service->getSessionsForPlan($plan);

        self::assertCount(4, $sessions);
        self::assertSame('SL', $sessions[3]['sessionType']);
        self::assertSame(90, $sessions[3]['totalMin']);
    }
}
