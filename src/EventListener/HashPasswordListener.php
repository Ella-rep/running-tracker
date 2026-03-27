<?php

namespace App\EventListener;

use ApiPlatform\Symfony\EventListener\EventPriorities;
use App\Entity\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsEventListener(event: KernelEvents::VIEW, priority: EventPriorities::PRE_WRITE)]
final class HashPasswordListener
{
    public function __construct(private UserPasswordHasherInterface $hasher) {}

    public function __invoke(ViewEvent $event): void
    {
        $user = $event->getControllerResult();

        if (!$user instanceof User || $event->getRequest()->getMethod() !== Request::METHOD_POST) {
            return;
        }

        if ($plain = $user->getPlainPassword()) {
            $user->setPassword($this->hasher->hashPassword($user, $plain));
            $user->eraseCredentials();
        }
    }
}
