<?php

namespace App\Service;

use App\Repository\UserRepository;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * Sends the weekly running recap email to subscribers.
 * Shared by the weekly cron command and the on-demand admin maintenance action.
 * Skips users with no run this week (unless on health pause). Respects the
 * email_hebdo opt-in flag (GDPR) via UserRepository::findWeeklyEmailSubscribers().
 */
final class WeeklySummaryMailer
{
    public function __construct(
        private UserRepository $userRepository,
        private WeeklySummaryService $weeklySummaryService,
        private MailerInterface $mailer,
        private Environment $twig,
    ) {
    }

    /**
     * Builds and sends the recap to every subscriber.
     *
     * @return array{sent:int, skipped:int, failed:int}
     */
    public function sendAll(?\DateTimeImmutable $now = null, bool $dryRun = false): array
    {
        $now ??= new \DateTimeImmutable('now');
        $weekLabel = $now->modify('monday this week')->format('d/m/Y');

        $from = $_ENV['MAILER_FROM'] ?? 'no-reply@runtracker.app';
        $appUrl = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');
        $dashboardUrl = $appUrl !== '' ? $appUrl . '/dashboard' : '/dashboard';
        $profileUrl = $appUrl !== '' ? $appUrl . '/profile' : '/profile';

        $sent = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($this->userRepository->findWeeklyEmailSubscribers() as $user) {
            $email = $user->getEmail();
            if ($email === null || $email === '') {
                $skipped++;
                continue;
            }

            $summary = $this->weeklySummaryService->buildSummary($user, $now);
            if ($summary === null) {
                $skipped++;
                continue;
            }

            $template = $summary['pause']
                ? 'email/weekly_summary_pause.html.twig'
                : 'email/weekly_summary.html.twig';

            $context = $summary + ['weekLabel' => $weekLabel, 'dashboardUrl' => $dashboardUrl, 'profileUrl' => $profileUrl];
            $html = $this->twig->render($template, $context);

            if ($dryRun) {
                $sent++;
                continue;
            }

            $message = (new Email())
                ->from($from)
                ->to($email)
                ->subject(sprintf('Semaine du %s — ton résumé running 🏃', $weekLabel))
                ->html($html)
                ->text($this->buildTextFallback($summary, $dashboardUrl, $profileUrl));

            try {
                $this->mailer->send($message);
                $sent++;
            } catch (TransportExceptionInterface) {
                $failed++;
            }
        }

        return ['sent' => $sent, 'skipped' => $skipped, 'failed' => $failed];
    }

    /** @param array<string,mixed> $s */
    private function buildTextFallback(array $s, string $dashboardUrl, string $profileUrl): string
    {
        $lines = ['Salut ' . $s['prenom'] . ',', '', 'Cette semaine :'];
        if ($s['pause']) {
            $lines[] = '🛌 Tu es en pause cette semaine.';
        } else {
            $lines[] = sprintf('📦 %d sorties · %s km · %s', $s['runs'], $s['km'], $s['duration']);
            if ($s['chargePct'] !== null) {
                $lines[] = sprintf('⚡ Charge : %d%% de ta base', $s['chargePct']);
            }
            if ($s['race'] !== null) {
                $lines[] = sprintf('🏁 %s dans %d jours', $s['race']['name'], $s['race']['days']);
            }
        }
        $lines[] = '';
        $lines[] = $s['conseil'];
        $lines[] = '';
        $lines[] = 'Voir mon dashboard : ' . $dashboardUrl;
        $lines[] = 'Gérer mes préférences : ' . $profileUrl;

        return implode("\n", $lines);
    }
}
