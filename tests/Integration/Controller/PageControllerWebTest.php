<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Integration tests for public page routes.
 */
final class PageControllerWebTest extends WebTestCase
{
    /**
     * Checks that home page is publicly reachable.
     */
    public function testHomePageIsSuccessful(): void
    {
        if (!class_exists('Symfony\\Component\\BrowserKit\\AbstractBrowser')) {
            self::markTestSkipped('symfony/browser-kit not installed yet.');
        }

        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('title', 'Accueil - Running Tracker');
    }

    /**
     * Checks that login page is publicly reachable.
     */
    public function testLoginPageIsSuccessful(): void
    {
        if (!class_exists('Symfony\\Component\\BrowserKit\\AbstractBrowser')) {
            self::markTestSkipped('symfony/browser-kit not installed yet.');
        }

        $client = static::createClient();
        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('title', 'Running Tracker — Connexion');
    }
}
