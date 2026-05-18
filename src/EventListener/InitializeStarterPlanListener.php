<?php

namespace App\EventListener;

use ApiPlatform\Symfony\EventListener\EventPriorities;
use App\Entity\Plan;
use App\Entity\PlanDetails;
use App\Entity\User;
use App\Repository\PlanRepository;
use App\Service\PlanSessionService;
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
        private PlanSessionService $planSessionService,
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

        $starterPlan = new Plan();
        $starterPlan->setUser($user);
        $starterPlan->setName('starter');
        $this->entityManager->persist($starterPlan);
        $this->entityManager->flush();

        $sessions = $this->planSessionService->getSessionsForPlan($starterPlan);
        foreach ($sessions as $index => $session) {
            $detail = new PlanDetails();
            $detail->setUser($user);
            $detail->setPlan($starterPlan);
            $detail->setPosition($index + 1);
            $detail->setSem($session['sem'] ?? null);
            $detail->setSessionDate($this->toDate($session['date'] ?? null));
            $detail->setFormat($session['format'] ?? "45'@Z2");
            $detail->setPe($session['pe'] ?? null);
            $detail->setTotalMin($session['totalMin'] ?? null);
            $detail->setIsOptional((bool) ($session['isOptional'] ?? false));
            $detail->setIsDone(false);
            $this->entityManager->persist($detail);
        }

        $this->entityManager->flush();
    }

    private function toDate(?string $value): ?\DateTimeImmutable
    {
        if (!$value) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
