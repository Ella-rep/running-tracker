<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\AdminAnnouncement;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AdminAnnouncement entity.
 */
final class AdminAnnouncementTest extends TestCase
{
    public function testDefaultStateAndFallbackTitle(): void
    {
        $announcement = new AdminAnnouncement();

        self::assertSame('', $announcement->getMessage());
        self::assertSame('Annonce', $announcement->getTitle());
        self::assertSame('info', $announcement->getLevel());
        self::assertTrue($announcement->isActive());
        self::assertNull($announcement->getStartsAt());
        self::assertNull($announcement->getEndsAt());
        self::assertNull($announcement->getCreatedByAdminId());
        self::assertInstanceOf(\DateTimeImmutable::class, $announcement->getCreatedAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $announcement->getUpdatedAt());

        $announcement->setTitle('   ');
        self::assertSame('Annonce', $announcement->getTitle());
    }

    public function testSettersTrimAndPersistValues(): void
    {
        $startsAt = new \DateTimeImmutable('2026-06-01 08:00:00');
        $endsAt = new \DateTimeImmutable('2026-06-15 20:00:00');

        $announcement = new AdminAnnouncement();
        $announcement
            ->setMessage('  Message admin  ')
            ->setTitle('  Maintenance  ')
            ->setLevel('warning')
            ->setIsActive(false)
            ->setStartsAt($startsAt)
            ->setEndsAt($endsAt)
            ->setCreatedByAdminId(42);

        self::assertSame('Message admin', $announcement->getMessage());
        self::assertSame('Maintenance', $announcement->getTitle());
        self::assertSame('warning', $announcement->getLevel());
        self::assertFalse($announcement->isActive());
        self::assertSame($startsAt, $announcement->getStartsAt());
        self::assertSame($endsAt, $announcement->getEndsAt());
        self::assertSame(42, $announcement->getCreatedByAdminId());
    }

    public function testTouchUpdatesUpdatedAt(): void
    {
        $announcement = new AdminAnnouncement();
        $before = $announcement->getUpdatedAt();

        $announcement->touch();

        self::assertNotSame($before, $announcement->getUpdatedAt());
    }
}
