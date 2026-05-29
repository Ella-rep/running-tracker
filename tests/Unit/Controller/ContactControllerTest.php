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

        self::assertSame('Impossible de lire une image jointe. Réessaie avec une autre image.', $result['errorMessage']);
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

        self::assertSame('Échec de traitement des images jointes. Réessaie avec une image plus légère.', $result['errorMessage']);
        self::assertTrue($result['storeOldForm']);
        self::assertNull($result['successMessage']);
    }

    /**
     * Ensures attachment guard rejects too many files.
     */
    public function testAttachmentsValidationErrorRejectsTooManyFiles(): void
    {
        $controller = $this->buildController($this->createMock(LoggerInterface::class));

        $attachments = [
            $this->createMock(UploadedFile::class),
            $this->createMock(UploadedFile::class),
            $this->createMock(UploadedFile::class),
            $this->createMock(UploadedFile::class),
        ];

        $error = $this->callPrivate($controller, 'attachmentsValidationError', [$attachments]);

        self::assertSame('Maximum 3 images autorisées.', $error);
    }

    /**
     * Ensures attachment guard rejects unsupported mime type.
     */
    public function testAttachmentsValidationErrorRejectsUnsupportedMime(): void
    {
        $controller = $this->buildController($this->createMock(LoggerInterface::class));

        $attachment = $this->createMock(UploadedFile::class);
        $attachment->method('isValid')->willReturn(true);
        $attachment->method('getSize')->willReturn(1200);
        $attachment->method('getMimeType')->willReturn('application/pdf');

        $error = $this->callPrivate($controller, 'attachmentsValidationError', [[$attachment]]);

        self::assertSame('Format image non pris en charge (jpg, png, webp, gif).', $error);
    }

    /**
     * Ensures validation fails when contact mailbox is missing.
     */
    public function testValidationErrorRejectsMissingContactMailbox(): void
    {
        $controller = $this->buildController($this->createMock(LoggerInterface::class), '');

        $error = $this->callPrivate($controller, 'validationError', ['idee', 'Sujet', 'Message valide', []]);

        self::assertSame('Configuration contact absente.', $error);
    }

    /**
     * Ensures helper keeps only UploadedFile instances from request payload.
     */
    public function testCollectAttachmentFilesKeepsUploadedFileOnly(): void
    {
        $controller = $this->buildController($this->createMock(LoggerInterface::class));

        $uploaded = $this->createMock(UploadedFile::class);
        $request = new \Symfony\Component\HttpFoundation\Request(
            files: ['attachments' => [$uploaded, 'skip-me']]
        );

        $files = $this->callPrivate($controller, 'collectAttachmentFiles', [$request]);

        self::assertCount(1, $files);
        self::assertSame($uploaded, $files[0]);
    }

    /**
     * Ensures filename sanitization keeps safe extension and normalized base.
     */
    public function testSanitizeAttachmentFilenameBuildsSafeName(): void
    {
        $controller = $this->buildController($this->createMock(LoggerInterface::class));

        $attachment = $this->createMock(UploadedFile::class);
        $attachment->method('getClientOriginalName')->willReturn('My Capture (v2).PNG');
        $attachment->method('getMimeType')->willReturn('image/png');
        $attachment->method('guessExtension')->willReturn('png');

        $safeName = $this->callPrivate($controller, 'sanitizeAttachmentFilename', [$attachment]);

        self::assertMatchesRegularExpression('/^My_Capture__v2_[a-f0-9]{8}\.png$/', $safeName);
    }

    private function buildController(LoggerInterface $logger, string $contactEmailTo = 'contact@example.test'): ContactController
    {
        $controller = new ContactController($contactEmailTo, 'no-reply@example.test', $logger);

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
