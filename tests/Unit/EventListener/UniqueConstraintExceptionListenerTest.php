<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\EventListener\UniqueConstraintExceptionListener;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Unit tests for UniqueConstraintExceptionListener.
 */
final class UniqueConstraintExceptionListenerTest extends TestCase
{
    /**
     * Ignores non unique-constraint exceptions.
     */
    public function testInvokeIgnoresNonUniqueConstraintException(): void
    {
        $listener = new UniqueConstraintExceptionListener();
        $event = $this->buildEvent(new \RuntimeException('boom'));

        $listener($event);

        self::assertNull($event->getResponse());
    }

    /**
     * Ignores unique constraint errors unrelated to username.
     */
    public function testInvokeIgnoresUnrelatedUniqueConstraint(): void
    {
        $listener = new UniqueConstraintExceptionListener();
        $event = $this->buildEvent($this->buildUniqueConstraintException('duplicate key on email_unique'));

        $listener($event);

        self::assertNull($event->getResponse());
    }

    /**
     * Converts username unique-constraint violation into a 422 hydra error.
     */
    public function testInvokeSetsHydra422ForUsernameUniqueConstraint(): void
    {
        $listener = new UniqueConstraintExceptionListener();
        $unique = $this->buildUniqueConstraintException('duplicate key value violates unique constraint "uniq_users" for username');
        $event = $this->buildEvent(new \RuntimeException('wrapped', 0, $unique));

        $listener($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame('hydra:Error', $payload['@type'] ?? null);
        self::assertSame("Ce nom d'utilisateur est déjà pris.", $payload['hydra:description'] ?? null);
    }

    private function buildEvent(\Throwable $throwable): ExceptionEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);

        return new ExceptionEvent(
            $kernel,
            Request::create('/api/test'),
            HttpKernelInterface::MAIN_REQUEST,
            $throwable,
        );
    }

    private function buildUniqueConstraintException(string $message): UniqueConstraintViolationException
    {
        $driverException = new class($message) extends \Exception implements DriverException {
            public function getSQLState()
            {
                return '23505';
            }
        };

        return new UniqueConstraintViolationException($driverException, null);
    }
}
