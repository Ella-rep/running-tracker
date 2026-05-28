<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Entity\AdminAnnouncement;
use App\Repository\AdminAnnouncementRepository;
use App\Twig\AdminAnnouncementExtension;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AdminAnnouncementExtension.
 */
final class AdminAnnouncementExtensionTest extends TestCase
{
    /**
     * Exposes the active_admin_announcement Twig function.
     */
    public function testGetFunctionsExposesActiveAnnouncement(): void
    {
        $repository = $this->createMock(AdminAnnouncementRepository::class);
        $extension = new AdminAnnouncementExtension($repository);

        $functions = $extension->getFunctions();

        self::assertCount(1, $functions);
        self::assertSame('active_admin_announcement', $functions[0]->getName());
    }

    /**
     * Returns normalized announcement payload and caches repository result.
     */
    public function testGetActiveAdminAnnouncementReturnsCachedNormalizedPayload(): void
    {
        $announcement = (new AdminAnnouncement())
            ->setTitle('Info')
            ->setLevel('warning')
            ->setMessage('Maintenance this evening')
            ->setEndsAt(new \DateTimeImmutable('2026-05-28 18:00:00'));

        $repository = $this->createMock(AdminAnnouncementRepository::class);
        $repository
            ->expects(self::once())
            ->method('findCurrent')
            ->willReturn($announcement);

        $extension = new AdminAnnouncementExtension($repository);

        $first = $extension->getActiveAdminAnnouncement();
        $second = $extension->getActiveAdminAnnouncement();

        self::assertIsArray($first);
        self::assertSame('Info', $first['title']);
        self::assertSame('warning', $first['level']);
        self::assertSame('Maintenance this evening', $first['message']);
        self::assertSame('28/05/2026 18:00', $first['endsAt']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $first['signature']);
        self::assertSame($first, $second);
    }

    /**
     * Returns null when repository fails to keep header rendering resilient.
     */
    public function testGetActiveAdminAnnouncementReturnsNullOnRepositoryFailure(): void
    {
        $repository = $this->createMock(AdminAnnouncementRepository::class);
        $repository
            ->method('findCurrent')
            ->willThrowException(new \RuntimeException('db unavailable'));

        $extension = new AdminAnnouncementExtension($repository);

        self::assertNull($extension->getActiveAdminAnnouncement());
    }
}

