<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\RunLog;
use App\Service\DashboardEfMetricsService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EF metrics dashboard computations.
 */
final class DashboardEfMetricsServiceTest extends TestCase
{
    /**
     * Returns empty section when not enough EF runs are available.
     */
    public function testBuildEfSectionReturnsEmptyStateWithSingleEfRun(): void
    {
        $service = new DashboardEfMetricsService();

        $section = $service->buildEfSection([
            $this->makeRun('2026-05-01', 'EF', '06:00', 150, 8.0),
        ]);

        self::assertFalse($section['hasData']);
        self::assertNotSame('', $section['emptyMessage']);
        self::assertSame([], $section['tableRows']);
    }

    /**
     * Computes KPIs when at least two EF runs exist.
     */
    public function testBuildEfKpisReturnsComputedTrendValues(): void
    {
        $service = new DashboardEfMetricsService();

        $kpis = $service->buildEfKpis([
            $this->makeRun('2026-05-01', 'EF', '06:00', 150, 8.0),
            $this->makeRun('2026-05-10', 'EF', '05:30', 145, 9.0),
        ]);

        self::assertSame('', $kpis['emptyMessage']);
        self::assertCount(3, $kpis['items']);
        self::assertSame('Gain d\'allure EF', $kpis['items'][0]['label']);
        self::assertStringContainsString('↗', $kpis['items'][0]['value']);
        self::assertStringContainsString('bpm', $kpis['items'][1]['value']);
        self::assertStringContainsString('Indice aérobie', $kpis['items'][2]['label']);
    }

    /**
     * Builds chart and table rows when valid EF dataset is provided.
     */
    public function testBuildEfSectionReturnsChartAndRowsWithValidData(): void
    {
        $service = new DashboardEfMetricsService();

        $section = $service->buildEfSection([
            $this->makeRun('2026-05-01', 'EF', '06:00', 150, 8.0),
            $this->makeRun('2026-05-03', 'RECOVERY', '06:20', 140, 5.0),
            $this->makeRun('2026-05-10', 'EF', '05:30', 145, 9.0),
        ]);

        self::assertTrue($section['hasData']);
        self::assertSame('', $section['emptyMessage']);
        self::assertCount(2, $section['tableRows']);
        self::assertNotEmpty($section['chart']['paceTicks']);
        self::assertNotEmpty($section['chart']['pacePoints']);
        self::assertNotEmpty($section['efBpmTrend']);
    }

    /**
     * Creates a RunLog test fixture with minimum needed fields.
     */
    private function makeRun(string $date, string $type, string $allure, int $bpm, float $km): RunLog
    {
        return (new RunLog())
            ->setDate($date)
            ->setRunType($type)
            ->setAllure($allure)
            ->setBpm($bpm)
            ->setKm($km);
    }
}
