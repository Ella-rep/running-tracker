<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\HealthPause;
use App\Entity\User;
use App\Repository\HealthPauseRepository;
use App\Service\HealthPauseService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class HealthPauseServiceTest extends TestCase
{
    private function makeService(HealthPauseRepository $repo, EntityManagerInterface $em): HealthPauseService
    {
        return new HealthPauseService($repo, $em);
    }

    private function makeRepo(): HealthPauseRepository
    {
        return $this->createMock(HealthPauseRepository::class);
    }

    private function makeEm(): EntityManagerInterface
    {
        return $this->createMock(EntityManagerInterface::class);
    }

    public function testActivateWithNoArgsDefaultsTypeToNull(): void
    {
        $user = new User();
        $repo = $this->makeRepo();
        $repo->method('findBy')->willReturn([]);
        $em = $this->makeEm();
        $em->expects(self::once())->method('persist')->with(self::isInstanceOf(HealthPause::class));
        $em->expects(self::once())->method('flush');

        $service = $this->makeService($repo, $em);
        $pause = $service->activate($user, null, null);

        self::assertNull($pause->getType());
        self::assertNull($pause->getResumedAt());
        self::assertTrue($pause->isActive());
        self::assertSame((new \DateTimeImmutable('today'))->format('Y-m-d'), $pause->getStartedAt());
    }

    public function testActivateWithAllArgsPersistsCorrectValues(): void
    {
        $user = new User();
        $repo = $this->makeRepo();
        $repo->method('findBy')->willReturn([]);
        $em = $this->makeEm();
        $em->expects(self::once())->method('persist');
        $em->expects(self::once())->method('flush');

        $service = $this->makeService($repo, $em);
        $pause = $service->activate($user, 'injury', 14);

        self::assertSame('injury', $pause->getType());
        self::assertSame(14, $pause->getEstimatedDays());
        self::assertTrue($pause->isActive());
    }

    public function testReActivateRemovesExistingRow(): void
    {
        $user = new User();
        $existing = (new HealthPause())->setUser($user)->setStartedAt('2026-06-01')->setResumedAt(null);

        $repo = $this->makeRepo();
        $repo->method('findBy')->willReturn([$existing]);
        $em = $this->makeEm();
        $em->expects(self::once())->method('remove')->with($existing);
        $em->expects(self::once())->method('persist');
        $em->expects(self::exactly(2))->method('flush');

        $service = $this->makeService($repo, $em);
        $pause = $service->activate($user, null, null);

        self::assertNotSame($existing, $pause);
    }

    public function testResumeSetsResumedAt(): void
    {
        $user = new User();
        $pause = (new HealthPause())->setUser($user)->setStartedAt('2026-06-01')->setResumedAt(null);

        $repo = $this->makeRepo();
        $repo->method('findActiveByUser')->willReturn($pause);
        $em = $this->makeEm();
        $em->expects(self::once())->method('flush');

        $service = $this->makeService($repo, $em);
        $service->resume($user);

        self::assertSame((new \DateTimeImmutable('today'))->format('Y-m-d'), $pause->getResumedAt());
    }

    public function testResumeIsNoOpWhenNoActiveRow(): void
    {
        $user = new User();
        $repo = $this->makeRepo();
        $repo->method('findActiveByUser')->willReturn(null);
        $em = $this->makeEm();
        $em->expects(self::never())->method('flush');

        $service = $this->makeService($repo, $em);
        $service->resume($user);
    }

    public function testGetStatusReturnsRecoveryWindowActiveWhenResumedLessThan7DaysAgo(): void
    {
        $user = new User();
        $resumedAt = (new \DateTimeImmutable('today'))->modify('-3 days')->format('Y-m-d');
        $pause = (new HealthPause())
            ->setUser($user)
            ->setStartedAt('2026-06-01')
            ->setResumedAt($resumedAt);

        $repo = $this->makeRepo();
        $repo->method('findBy')->willReturn([$pause]);
        $em = $this->makeEm();

        $service = $this->makeService($repo, $em);
        $status = $service->getStatus($user);

        self::assertFalse($status['active']);
        self::assertTrue($status['recoveryWindowActive']);
        self::assertGreaterThan(0, $status['recoveryDaysRemaining']);
    }

    public function testGetStatusReturnsRecoveryWindowFalseWhenResumed7OrMoreDaysAgo(): void
    {
        $user = new User();
        $resumedAt = (new \DateTimeImmutable('today'))->modify('-7 days')->format('Y-m-d');
        $pause = (new HealthPause())
            ->setUser($user)
            ->setStartedAt('2026-06-01')
            ->setResumedAt($resumedAt);

        $repo = $this->makeRepo();
        $repo->method('findBy')->willReturn([$pause]);
        $em = $this->makeEm();

        $service = $this->makeService($repo, $em);
        $status = $service->getStatus($user);

        self::assertFalse($status['active']);
        self::assertFalse($status['recoveryWindowActive']);
        self::assertSame(0, $status['recoveryDaysRemaining']);
    }

    public function testGetStatusReturnsAllNullDefaultsWhenNoRow(): void
    {
        $user = new User();
        $repo = $this->makeRepo();
        $repo->method('findBy')->willReturn([]);
        $em = $this->makeEm();

        $service = $this->makeService($repo, $em);
        $status = $service->getStatus($user);

        self::assertFalse($status['active']);
        self::assertNull($status['startedAt']);
        self::assertNull($status['type']);
        self::assertNull($status['estimatedDays']);
        self::assertNull($status['resumedAt']);
        self::assertFalse($status['recoveryWindowActive']);
        self::assertSame(0, $status['recoveryDaysRemaining']);
    }
}
