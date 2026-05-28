<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Collects recent Google OAuth failures from application log files.
 */
final class GoogleOAuthErrorReportService
{
    private const DEFAULT_HOURS = 24;

    public function __construct(
        #[Autowire('%kernel.logs_dir%')] private readonly string $logsDir,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
    ) {
    }

    /**
     * @return array{
     *   window_hours:int,
     *   count:int,
     *   files:list<string>,
     *   codes:array<string,int>,
     *   samples:list<string>
     * }
     */
    public function collectRecentErrors(int $hours = self::DEFAULT_HOURS, int $sampleLimit = 15): array
    {
        $windowHours = max(1, $hours);
        $cutoff = new \DateTimeImmutable(sprintf('-%d hours', $windowHours));
        $files = $this->resolveLogFiles();

        $count = 0;
        $codes = [];
        $samples = [];

        foreach ($files as $filePath) {
            $this->accumulateFileErrors($filePath, $cutoff, $sampleLimit, $count, $codes, $samples);
        }

        if ($sampleLimit > 0 && count($samples) > $sampleLimit) {
            $samples = array_slice($samples, -$sampleLimit);
        }

        arsort($codes);

        return [
            'window_hours' => $windowHours,
            'count' => $count,
            'files' => $files,
            'codes' => $codes,
            'samples' => $samples,
        ];
    }

    /**
     * @param array<string, int> $codes
     * @param list<string> $samples
     */
    private function accumulateFileErrors(
        string $filePath,
        \DateTimeImmutable $cutoff,
        int $sampleLimit,
        int &$count,
        array &$codes,
        array &$samples
    ): void {
        $lines = @file($filePath, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return;
        }

        foreach ($lines as $line) {
            if (!$this->shouldKeepLine($line, $cutoff)) {
                continue;
            }

            $count++;
            $code = $this->extractOauthErrorCode($line);
            $codes[$code] = ($codes[$code] ?? 0) + 1;

            if ($sampleLimit > 0) {
                $samples[] = $line;
            }
        }
    }

    private function shouldKeepLine(string $line, \DateTimeImmutable $cutoff): bool
    {
        if (!$this->isGoogleOAuthFailureLine($line)) {
            return false;
        }

        $lineTime = $this->extractLogDate($line);

        return $lineTime === null || $lineTime >= $cutoff;
    }

    /**
     * @param array{
     *   window_hours:int,
     *   count:int,
     *   files:list<string>,
     *   codes:array<string,int>,
     *   samples:list<string>
     * } $report
     */
    public function buildReportBody(array $report): string
    {
        $lines = [
            'Rapport erreurs OAuth Google',
            sprintf('Fenetre: %dh', (int) $report['window_hours']),
            sprintf('Total erreurs: %d', (int) $report['count']),
            '',
            'Repartition par code oauth_error:',
        ];

        if ($report['codes'] === []) {
            $lines[] = '- none';
        } else {
            foreach ($report['codes'] as $code => $hits) {
                $lines[] = sprintf('- %s: %d', $code, (int) $hits);
            }
        }

        $lines[] = '';
        $lines[] = 'Fichiers logs scannes:';
        foreach ($report['files'] as $file) {
            $lines[] = '- ' . $file;
        }

        $lines[] = '';
        $lines[] = 'Echantillon (dernieres lignes):';
        if ($report['samples'] === []) {
            $lines[] = '- Aucun evenement correspondant.';
        } else {
            foreach ($report['samples'] as $sample) {
                $lines[] = '- ' . $sample;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function resolveLogFiles(): array
    {
        $pattern = $this->logsDir . DIRECTORY_SEPARATOR . $this->environment . '*.log';
        $files = glob($pattern);
        if (!is_array($files)) {
            return [];
        }

        sort($files);

        return array_values(array_filter($files, static fn (string $path): bool => is_file($path) && is_readable($path)));
    }

    private function isGoogleOAuthFailureLine(string $line): bool
    {
        return str_contains($line, 'Google OAuth authentication failure.')
            || str_contains($line, 'OAuth Google:');
    }

    private function extractLogDate(string $line): ?\DateTimeImmutable
    {
        if (!preg_match('/^\[([^\]]+)\]/', $line, $matches)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($matches[1]);
        } catch (\Throwable) {
            return null;
        }
    }

    private function extractOauthErrorCode(string $line): string
    {
        if (preg_match('/"oauth_error":"([^"]+)"/', $line, $matches)) {
            $value = trim($matches[1]);
            if ($value !== '') {
                return $value;
            }
        }

        if (str_contains($line, 'access_denied')) {
            return 'access_denied';
        }

        return 'unknown';
    }
}
