<?php

namespace App\EventListener;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 10)]
final class UniqueConstraintExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $e = $event->getThrowable();

        // Unwrap nested exceptions
        while ($e !== null && !$e instanceof UniqueConstraintViolationException) {
            $e = $e->getPrevious();
        }

        if (!$e instanceof UniqueConstraintViolationException) {
            return;
        }

        // Only handle username uniqueness violations — let other constraint errors bubble normally
        $message = $e->getMessage();
        if (!str_contains($message, 'username') && !str_contains($message, 'uniq_users')) {
            return;
        }

        $event->setResponse(new JsonResponse([
            '@context'          => '/api/contexts/Error',
            '@type'             => 'hydra:Error',
            'hydra:title'       => 'An error occurred',
            'hydra:description' => "Ce nom d'utilisateur est déjà pris.",
        ], Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
