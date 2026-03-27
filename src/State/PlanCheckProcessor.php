<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\PlanCheck;
use App\Repository\PlanCheckRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

final class PlanCheckProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private PlanCheckRepository $repo,
        private Security $security,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PlanCheck
    {
        /** @var PlanCheck $data */
        $user = $this->security->getUser();

        // Upsert: find existing or use the new one
        $existing = $this->repo->findOneBy([
            'user'         => $user,
            'planKey'      => $data->getPlanKey(),
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
