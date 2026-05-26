<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Plan;
use Doctrine\ORM\EntityManagerInterface;

final class PlanDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if (!$data instanceof Plan) {
            throw new \InvalidArgumentException('Expected Plan entity.');
        }

        $this->entityManager->remove($data);
        $this->entityManager->flush();

        return null;
    }
}
