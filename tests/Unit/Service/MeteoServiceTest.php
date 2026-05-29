<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\MeteoService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MeteoService location resolution and default-city feedback.
 */
final class MeteoServiceTest extends TestCase
{
    /**
     * Falls back to Paris when no city is provided.
     */
    public function testBuildDailyAdviceUsesParisByDefaultWhenCityIsEmpty(): void
    {
        $service = new MeteoService();

        $advice = $service->buildDailyAdvice(null);

        self::assertIsArray($advice);
        self::assertSame('Paris', $advice['appliedCity'] ?? null);
        self::assertSame('default', $advice['cityStatus'] ?? null);
        self::assertSame(true, $advice['cityApplied'] ?? null);
    }

    /**
     * Falls back to Paris when user enters an unknown city.
     */
    public function testBuildDailyAdviceFallsBackToParisWhenCityIsUnknown(): void
    {
        $service = new MeteoService();

        $unknownCity = '__unknown_city_' . bin2hex(random_bytes(8));
        $advice = $service->buildDailyAdvice($unknownCity);

        self::assertIsArray($advice);
        self::assertSame('Paris', $advice['appliedCity'] ?? null);
        self::assertSame('error', $advice['cityStatus'] ?? null);
        self::assertSame(false, $advice['cityApplied'] ?? null);
        self::assertSame($unknownCity, $advice['requestedCity'] ?? null);
    }
}
