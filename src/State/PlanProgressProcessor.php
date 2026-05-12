<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\PlanProgress;
use App\Repository\PlanProgressRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

final class PlanProgressProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private PlanProgressRepository $repo,
        private Security $security,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PlanProgress
    {
        /** @var PlanProgress $data */
        $user = $this->security->getUser();

        $existing = $this->repo->findOneBy([
            'user' => $user,
            'planKey' => $data->getPlanKey(),
            'sessionIndex' => $data->getSessionIndex(),
        ]);

        if ($existing) {
            $existing->setDone($data->isDone());
            $this->em->flush();
            return $existing;
        }

        $data->setUser($user);
        $this->em->persist($data);
        $this->em->flush();

        return $data;
    }
}
