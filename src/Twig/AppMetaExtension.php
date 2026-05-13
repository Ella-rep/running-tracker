<?php

namespace App\Twig;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class AppMetaExtension extends AbstractExtension
{
    private ?string $cachedVersion = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('app_version', [$this, 'getAppVersion']),
        ];
    }

    public function getAppVersion(): string
    {
        if ($this->cachedVersion !== null) {
            return $this->cachedVersion;
        }

        $resolved = 'dev';
        $composerPath = $this->projectDir . DIRECTORY_SEPARATOR . 'composer.json';
        if (is_file($composerPath) && is_readable($composerPath)) {
            $raw = file_get_contents($composerPath);
            if ($raw !== false) {
                try {
                    $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
                    $version = $decoded['version'] ?? null;
                    if (is_string($version) && trim($version) !== '') {
                        $resolved = trim($version);
                    }
                } catch (\Throwable) {
                    // Keep fallback value.
                }
            }
        }

        $this->cachedVersion = $resolved;
        return $this->cachedVersion;
    }
}
