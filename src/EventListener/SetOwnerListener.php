<?php

namespace App\EventListener;

use ApiPlatform\Symfony\EventListener\EventPriorities;
use App\Entity\Race;
use App\Entity\RunLog;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::VIEW, priority: EventPriorities::PRE_WRITE)]
final class SetOwnerListener
{
    public function __construct(private Security $security) {}

    public function __invoke(ViewEvent $event): void
    {
        $object = $event->getControllerResult();
        $method = $event->getRequest()->getMethod();

        if (!in_array($method, [Request::METHOD_POST, Request::METHOD_PUT], true)) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user) return;

        if ($object instanceof RunLog || $object instanceof Race) {
            $object->setUser($user);
        }
    }
}
