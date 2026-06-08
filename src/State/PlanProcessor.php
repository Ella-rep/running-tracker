<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Plan;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class PlanProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private Security $security,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Plan
    {
        if (!$data instanceof Plan) {
            throw new \InvalidArgumentException('Expected Plan entity.');
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Authentication required.');
        }

        $isCreate = null === $data->getId();

        $data->setUser($user);
        if ($isCreate && strtolower(trim($data->getName())) === 'starter') {
            $data->setDashboardTracked(false);
        }
        $this->em->persist($data);
        $this->em->flush();

        // Starter/template sessions are no longer persisted on plan creation.
        // They are shown as non-persisted placeholders for empty plans instead.

        return $data;
    }
}
