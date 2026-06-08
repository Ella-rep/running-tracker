<?php

namespace App\EventListener;

use ApiPlatform\Symfony\EventListener\EventPriorities;
use App\Entity\Plan;
use App\Entity\User;
use App\Repository\PlanRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::VIEW, priority: EventPriorities::POST_WRITE)]
final class InitializeStarterPlanListener
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlanRepository $plans,
    ) {
    }

    public function __invoke(ViewEvent $event): void
    {
        $user = $event->getControllerResult();

        if (!$user instanceof User || $event->getRequest()->getMethod() !== Request::METHOD_POST) {
            return;
        }

        $existingStarter = $this->plans->findOneBy(['user' => $user, 'name' => 'starter']);
        if ($existingStarter instanceof Plan) {
            return;
        }

        // Create an empty starter plan. Its suggested sessions are rendered as
        // non-persisted placeholders while the plan has no real session.
        $starterPlan = new Plan();
        $starterPlan->setUser($user);
        $starterPlan->setName('starter');
        $starterPlan->setDashboardTracked(false);
        $this->entityManager->persist($starterPlan);
        $this->entityManager->flush();
    }
}
