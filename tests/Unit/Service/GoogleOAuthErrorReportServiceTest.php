<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\GoogleOAuthErrorReportService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for GoogleOAuthErrorReportService.
 */
final class GoogleOAuthErrorReportServiceTest extends TestCase
{
    /**
     * Parses recent Google OAuth errors and groups them by oauth_error code.
     */
    public function testCollectRecentErrorsParsesAndAggregatesCodes(): void
    {
        $logsDir = $this->createTempLogDir();
        $logPath = $logsDir . DIRECTORY_SEPARATOR . 'prod.log';

        $recent = (new \DateTimeImmutable('-1 hour'))->format(\DateTimeInterface::ATOM);
        $old = (new \DateTimeImmutable('-72 hours'))->format(\DateTimeInterface::ATOM);

        $content = implode("\n", [
            sprintf('[%s] app.ERROR: Google OAuth authentication failure. {"oauth_error":"access_denied"} []', $recent),
            sprintf('[%s] app.ERROR: Google OAuth authentication failure. {"oauth_error":"invalid_client"} []', $recent),
            sprintf('[%s] app.ERROR: Google OAuth authentication failure. {"oauth_error":"access_denied"} []', $old),
            sprintf('[%s] app.INFO: Irrelevant line []', $recent),
            '',
        ]);
        file_put_contents($logPath, $content);

        $service = new GoogleOAuthErrorReportService($logsDir, 'prod');
        $report = $service->collectRecentErrors(24, 10);

        self::assertSame(2, $report['count']);
        self::assertSame(1, $report['codes']['access_denied'] ?? 0);
        self::assertSame(1, $report['codes']['invalid_client'] ?? 0);
        self::assertSame([$logPath], $report['files']);
        self::assertCount(2, $report['samples']);

        $this->deleteDir($logsDir);
    }

    /**
     * Builds a readable plain-text report body.
     */
    public function testBuildReportBodyContainsSummaryAndSamples(): void
    {
        $service = new GoogleOAuthErrorReportService(sys_get_temp_dir(), 'prod');

        $body = $service->buildReportBody([
            'window_hours' => 24,
            'count' => 3,
            'files' => ['/var/log/prod.log'],
            'codes' => ['access_denied' => 2, 'unknown' => 1],
            'samples' => ['[2026-05-28T10:00:00+00:00] app.ERROR: Google OAuth authentication failure.'],
        ]);

        self::assertStringContainsString('Fenetre: 24h', $body);
        self::assertStringContainsString('Total erreurs: 3', $body);
        self::assertStringContainsString('- access_denied: 2', $body);
        self::assertStringContainsString('/var/log/prod.log', $body);
        self::assertStringContainsString('Google OAuth authentication failure.', $body);
    }

    private function createTempLogDir(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gmail-oauth-log-test-' . bin2hex(random_bytes(6));
        mkdir($dir);

        return $dir;
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->deleteDir($path);
                continue;
            }

            unlink($path);
        }

        rmdir($dir);
    }
}
