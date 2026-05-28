<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Displays and handles the contact form.
 */
final class ContactController extends AbstractController
{
    private const FLASH_ERROR = 'error';
    private const FLASH_SUCCESS = 'success';
    private const OLD_FORM_SESSION_KEY = 'contact.old_form';
    private const MIN_SUBMIT_DELAY_SECONDS = 3;
    private const MAX_ATTACHMENTS = 3;
    private const MAX_ATTACHMENT_SIZE_BYTES = 5_000_000;

    /** @var array<string, string> */
    private const ALLOWED_ATTACHMENT_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    /** @var array<string, string> */
    private const MOTIF_LABELS = [
        'idee' => 'Boite a idee',
        'bug' => 'Signaler un bug',
        'question' => 'Questions',
        'autre' => 'Autre',
    ];

    public function __construct(
        #[Autowire('%env(string:CONTACT_EMAIL_TO)%')] private readonly string $contactEmailTo,
        #[Autowire('%env(string:MAILER_FROM)%')] private readonly string $mailerFrom,
    ) {
    }

    /**
     * Displays the contact page.
     */
    #[Route('/contact', name: 'app_contact', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $oldForm = $request->getSession()?->remove(self::OLD_FORM_SESSION_KEY);
        if (!is_array($oldForm)) {
            $oldForm = [
                'motif' => '',
                'subject' => '',
                'message' => '',
            ];
        }

        return $this->render('contact/index.html.twig', [
            'username' => $this->getUser()?->getUserIdentifier(),
            'motifs' => self::MOTIF_LABELS,
            'oldForm' => $oldForm,
            'contactStartedAt' => (string) time(),
        ]);
    }

    /**
     * Sends a contact email through Symfony Mailer (Brevo DSN).
     */
    #[Route('/contact/send', name: 'app_contact_send', methods: ['POST'])]
    public function send(Request $request, MailerInterface $mailer): RedirectResponse
    {
        $motif = trim((string) $request->request->get('motif', ''));
        $subject = trim((string) $request->request->get('subject', ''));
        $message = trim((string) $request->request->get('message', ''));
        $attachments = $this->collectAttachmentFiles($request);
        $outcome = $this->analyzeSubmission($request, $motif, $subject, $message, $attachments);
        $outcome = $this->dispatchWhenAllowed($mailer, $motif, $subject, $message, $attachments, $outcome);
        $this->storeOldFormWhenNeeded($request, $outcome);
        $this->flashOutcome($outcome);

        return $this->redirectToRoute('app_contact');
    }

    /**
     * @param array{canSend: bool, storeOldForm: bool, errorMessage: ?string, successMessage: ?string} $outcome
     * @return array{canSend: bool, storeOldForm: bool, errorMessage: ?string, successMessage: ?string}
     */
    private function dispatchWhenAllowed(MailerInterface $mailer, string $motif, string $subject, string $message, array $attachments, array $outcome): array
    {
        if ((bool) $outcome['canSend']) {
            return $this->dispatchContactEmail($mailer, $motif, $subject, $message, $attachments, $outcome);
        }

        return $outcome;
    }

    /**
     * @param array{canSend: bool, storeOldForm: bool, errorMessage: ?string, successMessage: ?string} $outcome
     */
    private function storeOldFormWhenNeeded(Request $request, array $outcome): void
    {
        if ((bool) $outcome['storeOldForm']) {
            $this->storeOldForm($request);
        }
    }

    /**
     * @param array{canSend: bool, storeOldForm: bool, errorMessage: ?string, successMessage: ?string} $outcome
     */
    private function flashOutcome(array $outcome): void
    {
        if (is_string($outcome['errorMessage']) && $outcome['errorMessage'] !== '') {
            $this->addFlash(self::FLASH_ERROR, $outcome['errorMessage']);
            return;
        }

        if (is_string($outcome['successMessage']) && $outcome['successMessage'] !== '') {
            $this->addFlash(self::FLASH_SUCCESS, $outcome['successMessage']);
        }
    }

    /**
     * @return array{canSend: bool, storeOldForm: bool, errorMessage: ?string, successMessage: ?string}
     */
    private function analyzeSubmission(Request $request, string $motif, string $subject, string $message, array $attachments): array
    {
        $botField = trim((string) $request->request->get('company', ''));
        $startedAt = (int) $request->request->get('started_at', 0);
        $canSend = true;
        $storeOldForm = false;
        $errorMessage = null;
        $successMessage = null;

        if ($botField !== '') {
            $canSend = false;
            $successMessage = 'Message envoye. Merci pour ton retour.';
        }

        if ($canSend && ($startedAt <= 0 || (time() - $startedAt) < self::MIN_SUBMIT_DELAY_SECONDS)) {
            $canSend = false;
            $storeOldForm = true;
            $errorMessage = 'Envoi trop rapide. Reessaie dans quelques secondes.';
        }

        if ($canSend && !$this->isCsrfTokenValid('contact.send', (string) $request->request->get('_token', ''))) {
            $canSend = false;
            $storeOldForm = true;
            $errorMessage = 'Jeton CSRF invalide.';
        }

        if ($canSend) {
            $validationError = $this->validationError($motif, $subject, $message, $attachments);
            if ($validationError !== null) {
                $canSend = false;
                $storeOldForm = true;
                $errorMessage = $validationError;
            }
        }

        return [
            'canSend' => $canSend,
            'storeOldForm' => $storeOldForm,
            'errorMessage' => $errorMessage,
            'successMessage' => $successMessage,
        ];
    }

    /**
     * @param array{canSend: bool, storeOldForm: bool, errorMessage: ?string, successMessage: ?string} $outcome
     * @return array{canSend: bool, storeOldForm: bool, errorMessage: ?string, successMessage: ?string}
     */
    private function dispatchContactEmail(MailerInterface $mailer, string $motif, string $subject, string $message, array $attachments, array $outcome): array
    {
        $actor = $this->getUser();
        $senderIdentifier = $actor?->getUserIdentifier() ?? 'visiteur';
        $senderEmail = $actor instanceof User ? $actor->getEmail() : null;

        $email = (new Email())
            ->from($this->mailerFrom)
            ->to($this->contactEmailTo)
            ->subject(sprintf('[Contact:%s] %s', strtoupper($motif), $subject))
            ->text($this->buildBodyText(
                self::MOTIF_LABELS[$motif],
                $senderIdentifier,
                $senderEmail,
                $subject,
                $message
            ));

        foreach ($attachments as $attachment) {
            $path = $attachment->getRealPath();
            if (!is_string($path) || $path === '') {
                continue;
            }

            $safeName = $this->sanitizeAttachmentFilename($attachment);
            $email->attachFromPath($path, $safeName, $attachment->getMimeType() ?: 'application/octet-stream');
        }

        if (is_string($senderEmail) && $senderEmail !== '') {
            $email->replyTo($senderEmail);
        }

        try {
            $mailer->send($email);
            $outcome['successMessage'] = 'Message envoye. Merci pour ton retour.';
        } catch (TransportExceptionInterface) {
            $outcome['errorMessage'] = 'Echec envoi email. Reessaie plus tard.';
            $outcome['storeOldForm'] = true;
        }

        return $outcome;
    }

    private function storeOldForm(Request $request): void
    {
        $request->getSession()?->set(self::OLD_FORM_SESSION_KEY, [
            'motif' => trim((string) $request->request->get('motif', '')),
            'subject' => trim((string) $request->request->get('subject', '')),
            'message' => trim((string) $request->request->get('message', '')),
        ]);
    }

    private function validationError(string $motif, string $subject, string $message, array $attachments): ?string
    {
        $errorMessage = null;

        if (!isset(self::MOTIF_LABELS[$motif])) {
            $errorMessage = 'Motif invalide.';
        } elseif ($subject === '' || mb_strlen($subject) > 180) {
            $errorMessage = 'Sujet requis (180 caracteres max).';
        } elseif ($message === '' || mb_strlen($message) > 5000) {
            $errorMessage = 'Message requis (5000 caracteres max).';
        } elseif (($attachmentError = $this->attachmentsValidationError($attachments)) !== null) {
            $errorMessage = $attachmentError;
        } elseif ($this->contactEmailTo === '') {
            $errorMessage = 'Configuration contact absente.';
        }

        return $errorMessage;
    }

    private function buildBodyText(
        string $motifLabel,
        string $senderIdentifier,
        ?string $senderEmail,
        string $subject,
        string $message
    ): string {
        return implode("\n", [
            'Contact Running Tracker',
            '----------------------',
            'Motif: ' . $motifLabel,
            'Utilisateur: ' . $senderIdentifier,
            'Email utilisateur: ' . ($senderEmail ?: 'non renseigne'),
            'Sujet: ' . $subject,
            '',
            'Message:',
            $message,
        ]);
    }

    /** @return array<int, UploadedFile> */
    private function collectAttachmentFiles(Request $request): array
    {
        $input = $request->files->all('attachments');
        if ($input instanceof UploadedFile) {
            return [$input];
        }

        if (!is_array($input)) {
            return [];
        }

        $files = [];
        foreach ($input as $item) {
            if ($item instanceof UploadedFile) {
                $files[] = $item;
            }
        }

        return $files;
    }

    /** @param array<int, UploadedFile> $attachments */
    private function attachmentsValidationError(array $attachments): ?string
    {
        $errorMessage = null;

        if (count($attachments) > self::MAX_ATTACHMENTS) {
            $errorMessage = sprintf('Maximum %d images autorisees.', self::MAX_ATTACHMENTS);
        } else {
            foreach ($attachments as $attachment) {
                if (!$attachment->isValid()) {
                    $errorMessage = 'Une image jointe est invalide. Reessaie l\'envoi.';
                    break;
                }

                if ($attachment->getSize() > self::MAX_ATTACHMENT_SIZE_BYTES) {
                    $errorMessage = 'Une image depasse 5 Mo. Reduis sa taille puis reessaie.';
                    break;
                }

                $mimeType = (string) $attachment->getMimeType();
                if (!isset(self::ALLOWED_ATTACHMENT_MIME_TYPES[$mimeType])) {
                    $errorMessage = 'Format image non pris en charge (jpg, png, webp, gif).';
                    break;
                }
            }
        }

        return $errorMessage;
    }

    private function sanitizeAttachmentFilename(UploadedFile $file): string
    {
        $base = pathinfo((string) $file->getClientOriginalName(), PATHINFO_FILENAME);
        $base = preg_replace('/[^a-z0-9_-]/i', '_', (string) $base) ?: 'capture';
        $base = trim($base, '_');
        $base = $base !== '' ? substr($base, 0, 60) : 'capture';

        $mimeType = (string) $file->getMimeType();
        $ext = self::ALLOWED_ATTACHMENT_MIME_TYPES[$mimeType] ?? strtolower((string) $file->guessExtension());
        $ext = preg_replace('/[^a-z0-9]/i', '', (string) $ext);
        $ext = $ext !== '' ? $ext : 'bin';

        return sprintf('%s_%s.%s', $base, substr(sha1(uniqid('', true)), 0, 8), $ext);
    }
}
