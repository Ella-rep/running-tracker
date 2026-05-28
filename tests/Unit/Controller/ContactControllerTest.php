<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\ContactController;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Unit tests for contact email dispatch safeguards.
 */
final class ContactControllerTest extends TestCase
{
    /**
     * Ensures unreadable attachments return a user-friendly error without crashing.
     */
    public function testDispatchContactEmailReturnsErrorWhenAttachmentIsUnreadable(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $controller = $this->buildController($logger);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $attachment = $this->createMock(UploadedFile::class);
        $attachment->method('getRealPath')->willReturn('');

        $outcome = [
            'canSend' => true,
            'storeOldForm' => false,
            'errorMessage' => null,
            'successMessage' => null,
        ];

        $result = $this->callPrivate(
            $controller,
            'dispatchContactEmail',
            [$mailer, 'idee', 'Sujet test', 'Message test', [$attachment], $outcome]
        );

        self::assertSame('Impossible de lire une image jointe. Reessaie avec une autre image.', $result['errorMessage']);
        self::assertTrue($result['storeOldForm']);
        self::assertNull($result['successMessage']);
    }

    /**
     * Ensures unexpected mailer/attachment runtime errors are caught and logged.
     */
    public function testDispatchContactEmailHandlesUnexpectedThrowable(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('error')
            ->with(
                'Unexpected contact email failure.',
                self::callback(static function (array $context): bool {
                    return ($context['exception_class'] ?? null) === \RuntimeException::class
                        && ($context['attachments_count'] ?? null) === 0;
                })
            );

        $controller = $this->buildController($logger);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer
            ->expects(self::once())
            ->method('send')
            ->willThrowException(new \RuntimeException('boom'));

        $outcome = [
            'canSend' => true,
            'storeOldForm' => false,
            'errorMessage' => null,
            'successMessage' => null,
        ];

        $result = $this->callPrivate(
            $controller,
            'dispatchContactEmail',
            [$mailer, 'bug', 'Sujet test', 'Message test', [], $outcome]
        );

        self::assertSame('Echec de traitement des images jointes. Reessaie avec une image plus legere.', $result['errorMessage']);
        self::assertTrue($result['storeOldForm']);
        self::assertNull($result['successMessage']);
    }

    private function buildController(LoggerInterface $logger): ContactController
    {
        $controller = new ContactController('contact@example.test', 'no-reply@example.test', $logger);

        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);

        $container = new Container();
        $container->set('security.token_storage', $tokenStorage);
        $controller->setContainer($container);

        return $controller;
    }

    private function callPrivate(object $target, string $methodName, array $args): mixed
    {
        $method = new \ReflectionMethod($target, $methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($target, $args);
    }
}
