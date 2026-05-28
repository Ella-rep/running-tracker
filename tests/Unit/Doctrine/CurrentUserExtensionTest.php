<?php

declare(strict_types=1);

namespace App\Tests\Unit\Doctrine;

use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use App\Doctrine\CurrentUserExtension;
use App\Entity\Plan;
use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Unit tests for CurrentUserExtension.
 */
final class CurrentUserExtensionTest extends TestCase
{
    /**
     * Applies current user restriction on owned collection resources.
     */
    public function testApplyToCollectionAddsConstraintForOwnedResource(): void
    {
        $user = (new User())
            ->setUsername('owner')
            ->setEmail('owner@example.test')
            ->setPassword('hashed');

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder
            ->expects(self::once())
            ->method('getRootAliases')
            ->willReturn(['o']);
        $queryBuilder
            ->expects(self::once())
            ->method('andWhere')
            ->with('o.user = :current_user')
            ->willReturnSelf();
        $queryBuilder
            ->expects(self::once())
            ->method('setParameter')
            ->with('current_user', $user)
            ->willReturnSelf();

        $extension = new CurrentUserExtension($security);
        $nameGenerator = $this->createMock(QueryNameGeneratorInterface::class);

        $extension->applyToCollection($queryBuilder, $nameGenerator, Plan::class);
    }

    /**
     * Skips query changes for resources that are not user-owned.
     */
    public function testApplyToCollectionSkipsUnsupportedResource(): void
    {
        $security = $this->createMock(Security::class);
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->expects(self::never())->method('getRootAliases');
        $queryBuilder->expects(self::never())->method('andWhere');
        $queryBuilder->expects(self::never())->method('setParameter');

        $extension = new CurrentUserExtension($security);
        $nameGenerator = $this->createMock(QueryNameGeneratorInterface::class);

        $extension->applyToCollection($queryBuilder, $nameGenerator, User::class);
    }

    /**
     * Skips query changes when there is no authenticated user.
     */
    public function testApplyToItemSkipsWhenNoAuthenticatedUser(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->expects(self::never())->method('getRootAliases');
        $queryBuilder->expects(self::never())->method('andWhere');
        $queryBuilder->expects(self::never())->method('setParameter');

        $extension = new CurrentUserExtension($security);
        $nameGenerator = $this->createMock(QueryNameGeneratorInterface::class);

        $extension->applyToItem($queryBuilder, $nameGenerator, Plan::class, ['id' => 1]);
    }
}

