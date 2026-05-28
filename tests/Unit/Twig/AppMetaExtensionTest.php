<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Twig\AppMetaExtension;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AppMetaExtension.
 */
final class AppMetaExtensionTest extends TestCase
{
    /**
     * Exposes the app_version Twig function.
     */
    public function testGetFunctionsExposesAppVersion(): void
    {
        $extension = new AppMetaExtension(self::createTempDir());

        $functions = $extension->getFunctions();

        self::assertCount(1, $functions);
        self::assertSame('app_version', $functions[0]->getName());
    }

    /**
     * Reads version from composer.json and caches it for subsequent calls.
     */
    public function testGetAppVersionReadsAndCachesComposerVersion(): void
    {
        $tempDir = self::createTempDir();
        file_put_contents($tempDir . DIRECTORY_SEPARATOR . 'composer.json', '{"version":"1.2.3"}');

        $extension = new AppMetaExtension($tempDir);

        self::assertSame('1.2.3', $extension->getAppVersion());

        file_put_contents($tempDir . DIRECTORY_SEPARATOR . 'composer.json', '{"version":"9.9.9"}');
        self::assertSame('1.2.3', $extension->getAppVersion());

        self::deleteDir($tempDir);
    }

    /**
     * Falls back to dev when composer.json is missing or malformed.
     */
    public function testGetAppVersionFallsBackToDev(): void
    {
        $missingDir = self::createTempDir();
        $extensionMissing = new AppMetaExtension($missingDir);
        self::assertSame('dev', $extensionMissing->getAppVersion());

        $invalidDir = self::createTempDir();
        file_put_contents($invalidDir . DIRECTORY_SEPARATOR . 'composer.json', '{bad-json');
        $extensionInvalid = new AppMetaExtension($invalidDir);
        self::assertSame('dev', $extensionInvalid->getAppVersion());

        self::deleteDir($missingDir);
        self::deleteDir($invalidDir);
    }

    private static function createTempDir(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'app-meta-test-' . bin2hex(random_bytes(6));
        mkdir($dir);

        return $dir;
    }

    private static function deleteDir(string $dir): void
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
                self::deleteDir($path);
                continue;
            }

            unlink($path);
        }

        rmdir($dir);
    }
}

