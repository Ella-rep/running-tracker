<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Plan;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Handles PUT updates on a Plan resource (e.g. rename). Blocks any edit
 * once the plan is archived; archiving/unarchiving itself goes through the
 * dedicated /api/plans/{id}/archive endpoint, not this operation.
 */
final class PlanPutProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Plan
    {
        if (!$data instanceof Plan) {
            throw new \InvalidArgumentException('Expected Plan entity.');
        }

        if ($data->isArchived()) {
            throw new ConflictHttpException('Plan archivé : non modifiable.');
        }

        $this->em->persist($data);
        $this->em->flush();

        return $data;
    }
}
