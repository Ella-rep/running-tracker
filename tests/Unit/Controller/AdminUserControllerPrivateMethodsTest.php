<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\AdminUserController;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for private helper methods in AdminUserController.
 */
final class AdminUserControllerPrivateMethodsTest extends TestCase
{
    /**
     * Validates date boundary parsing for search filters.
     */
    public function testParseDateBoundary(): void
    {
        $controller = new AdminUserController();

        self::assertNull($this->callPrivate($controller, 'parseDateBoundary', ['', false]));

        $start = $this->callPrivate($controller, 'parseDateBoundary', ['2026-05-28', false]);
        self::assertInstanceOf(DateTimeImmutable::class, $start);
        self::assertSame('2026-05-28 00:00:00', $start->format('Y-m-d H:i:s'));

        $end = $this->callPrivate($controller, 'parseDateBoundary', ['2026-05-28', true]);
        self::assertInstanceOf(DateTimeImmutable::class, $end);
        self::assertSame('2026-05-28 23:59:59', $end->format('Y-m-d H:i:s'));
    }

    /**
     * Validates datetime-local input parsing.
     */
    public function testParseDateTimeLocal(): void
    {
        $controller = new AdminUserController();

        self::assertNull($this->callPrivate($controller, 'parseDateTimeLocal', ['']));
        self::assertNull($this->callPrivate($controller, 'parseDateTimeLocal', ['invalid']));

        $parsed = $this->callPrivate($controller, 'parseDateTimeLocal', ['2026-05-28T14:30']);
        self::assertInstanceOf(DateTimeImmutable::class, $parsed);
        self::assertSame('2026-05-28 14:30:00', $parsed->format('Y-m-d H:i:s'));
    }

    /**
     * Validates announcement text constraints.
     */
    public function testValidateAnnouncementText(): void
    {
        $controller = new AdminUserController();

        self::assertSame(
            'Annonce trop courte (minimum 6 caracteres).',
            $this->callPrivate($controller, 'validateAnnouncementText', ['abc', 'Titre'])
        );

        self::assertSame(
            'Titre annonce trop long (120 caracteres max).',
            $this->callPrivate($controller, 'validateAnnouncementText', [str_repeat('a', 8), str_repeat('t', 121)])
        );

        self::assertNull($this->callPrivate($controller, 'validateAnnouncementText', ['Message valide', 'Titre']));
    }

    /**
     * Validates level normalization aliases.
     */
    public function testNormalizeAnnouncementLevel(): void
    {
        $controller = new AdminUserController();

        self::assertSame('danger', $this->callPrivate($controller, 'normalizeAnnouncementLevel', ['critical']));
        self::assertSame('success', $this->callPrivate($controller, 'normalizeAnnouncementLevel', ['ok']));
        self::assertSame('info', $this->callPrivate($controller, 'normalizeAnnouncementLevel', ['info']));
        self::assertNull($this->callPrivate($controller, 'normalizeAnnouncementLevel', ['unexpected']));
    }

    /**
     * Validates date consistency rules for announcements.
     */
    public function testValidateAnnouncementDates(): void
    {
        $controller = new AdminUserController();

        self::assertSame(
            'Date de debut invalide.',
            $this->callPrivate($controller, 'validateAnnouncementDates', ['2026-05-28T10:00', '', null, null])
        );

        self::assertSame(
            'Date de fin invalide.',
            $this->callPrivate($controller, 'validateAnnouncementDates', ['', '2026-05-28T10:00', null, null])
        );

        $start = new DateTimeImmutable('2026-05-28 12:00:00');
        $endBefore = new DateTimeImmutable('2026-05-28 11:00:00');
        self::assertSame(
            'La date de fin doit etre apres la date de debut.',
            $this->callPrivate($controller, 'validateAnnouncementDates', ['2026-05-28T12:00', '2026-05-28T11:00', $start, $endBefore])
        );

        $endAfter = new DateTimeImmutable('2026-05-28 13:00:00');
        self::assertNull(
            $this->callPrivate($controller, 'validateAnnouncementDates', ['2026-05-28T12:00', '2026-05-28T13:00', $start, $endAfter])
        );
    }

    /**
     * Validates audit action labels and top action resolver.
     */
    public function testAuditActionHelpers(): void
    {
        $controller = new AdminUserController();

        self::assertSame('Aucune action', $this->callPrivate($controller, 'humanizeAuditAction', [null]));
        self::assertSame('Reset mot de passe', $this->callPrivate($controller, 'humanizeAuditAction', ['user_reset_password']));
        self::assertSame('custom event', $this->callPrivate($controller, 'humanizeAuditAction', ['custom_event']));

        $top = $this->callPrivate($controller, 'resolveTopUserAction', [[
            'user_create' => 3,
            'run_log_create' => 9,
            'user_delete' => 1,
        ]]);

        self::assertSame('run_log_create', $top['action']);
        self::assertSame(9, $top['count']);

        $none = $this->callPrivate($controller, 'resolveTopUserAction', [['user_create' => 0]]);
        self::assertSame('', $none['action']);
        self::assertSame(0, $none['count']);
    }

    /**
     * Calls a private method via reflection.
     */
    private function callPrivate(object $target, string $methodName, array $args): mixed
    {
        $method = new \ReflectionMethod($target, $methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($target, $args);
    }
}
