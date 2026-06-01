<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\RunLog;
use App\Repository\PlanDetailsRepository;
use App\Repository\PlanProgressRepository;
use App\Repository\PlanRepository;
use App\Repository\RunLogRepository;
use App\Service\DashboardAdvancedMetricsService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for training-load advice wording and context.
 */
final class DashboardAdvancedMetricsServiceTest extends TestCase
{
    /**
     * Keeps default under-load recommendation when last three runs are not all difficult.
     */
    public function testBuildTrainingLoadKeepsDefaultUnderLoadRecommendationWithoutThreeDifficultRuns(): void
    {
        $service = $this->makeService();

        $logs = array_merge(
            $this->makeBaselineRuns(),
            [
                $this->makeRun(1, perceivedEffort: 'difficile', notes: 'sortie vent'),
                $this->makeRun(2, perceivedEffort: 'facile', notes: 'endurance calme'),
                $this->makeRun(3, perceivedEffort: 'difficile', notes: 'bonne sensation'),
            ]
        );

        $load = $service->buildTrainingLoad($logs);

        self::assertTrue($load['hasData']);
        self::assertSame('under', $load['statusKey']);
        self::assertSame('Sous-charge', $load['statusLabel']);
        self::assertSame('Tu peux ajouter une séance facile ou un peu de volume progressif.', $load['recommendation']);
    }

    /**
     * Uses difficult-runs contextual wording when last three runs are all difficult.
     */
    public function testBuildTrainingLoadAddsDifficultRunsContextWhenLastThreeAreDifficult(): void
    {
        $service = $this->makeService();

        $logs = array_merge(
            $this->makeBaselineRuns(),
            [
                $this->makeRun(1, perceivedEffort: 'difficile', notes: 'sortie exigeante'),
                $this->makeRun(2, perceivedEffort: 'difficile', notes: 'jambes lourdes'),
                $this->makeRun(3, perceivedEffort: 'difficile', notes: 'rythme dur'),
            ]
        );

        $load = $service->buildTrainingLoad($logs);

        self::assertSame('under', $load['statusKey']);
        self::assertStringContainsString('tes 3 dernières sorties ont été tagguées difficiles', $load['recommendation']);
        self::assertStringNotContainsString('liées à la chaleur', $load['recommendation']);
    }


    /**
     * Creates a service instance with mocked repositories (unused by buildTrainingLoad).
     */
    private function makeService(): DashboardAdvancedMetricsService
    {
        return new DashboardAdvancedMetricsService(
            $this->createMock(RunLogRepository::class),
            $this->createMock(PlanRepository::class),
            $this->createMock(PlanDetailsRepository::class),
            $this->createMock(PlanProgressRepository::class)
        );
    }

    /**
     * @return array<int,RunLog>
     */
    private function makeBaselineRuns(): array
    {
        $runs = [];
        foreach ([10, 12, 14, 16, 18, 20, 22, 24, 26] as $daysAgo) {
            $runs[] = $this->makeRun($daysAgo, duration: '01:00:00', runType: 'EF', perceivedEffort: 'facile');
        }

        return $runs;
    }

    /**
     * Creates a RunLog fixture with date relative to current day.
     */
    private function makeRun(
        int $daysAgo,
        string $duration = '00:30:00',
        string $runType = 'EF',
        ?string $perceivedEffort = null,
        ?string $notes = null
    ): RunLog {
        $date = (new \DateTimeImmutable('today'))
            ->sub(new \DateInterval(sprintf('P%dD', $daysAgo)))
            ->format('Y-m-d');

        return (new RunLog())
            ->setDate($date)
            ->setDuration($duration)
            ->setRunType($runType)
            ->setPerceivedEffort($perceivedEffort)
            ->setNotes($notes);
    }
}
