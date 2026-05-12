<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Plan;
use App\Entity\PlanDetails;
use App\Entity\PlanProgress;
use App\Entity\Race;
use App\Entity\RunLog;
use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('api_platform.doctrine.orm.query_extension.collection')]
#[AutoconfigureTag('api_platform.doctrine.orm.query_extension.item')]
final class CurrentUserExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    private const OWNED_RESOURCES = [
        Plan::class,
        PlanDetails::class,
        PlanProgress::class,
        RunLog::class,
        Race::class,
    ];

    public function __construct(private Security $security)
    {
    }

    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = []
    ): void {
        $this->addCurrentUserConstraint($queryBuilder, $resourceClass);
    }

    public function applyToItem(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        array $identifiers,
        ?Operation $operation = null,
        array $context = []
    ): void {
        $this->addCurrentUserConstraint($queryBuilder, $resourceClass);
    }

    private function addCurrentUserConstraint(QueryBuilder $queryBuilder, string $resourceClass): void
    {
        if (!in_array($resourceClass, self::OWNED_RESOURCES, true)) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];
        $queryBuilder
            ->andWhere(sprintf('%s.user = :current_user', $rootAlias))
            ->setParameter('current_user', $user);
    }
}
