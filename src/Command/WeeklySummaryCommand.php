<?php

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\WeeklySummaryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * Sends the weekly running digest. Intended to run via cron on Monday morning:
 *   0 7 * * 1   bin/console app:weekly-summary
 * Skips users with no run this week (unless on health pause, where a lighter
 * template is used). Respects the email_hebdo opt-in flag (GDPR).
 */
#[AsCommand(name: 'app:weekly-summary', description: 'Envoie le résumé running hebdomadaire par mail.')]
final class WeeklySummaryCommand extends Command
{
    public function __construct(
        private UserRepository $userRepository,
        private WeeklySummaryService $weeklySummaryService,
        private MailerInterface $mailer,
        private Environment $twig,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Calcule sans envoyer les mails.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $now = new \DateTimeImmutable('now');
        $weekLabel = $now->modify('monday this week')->format('d/m/Y');

        $from = $_ENV['MAILER_FROM'] ?? 'no-reply@runtracker.app';
        $appUrl = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');
        $dashboardUrl = $appUrl !== '' ? $appUrl . '/dashboard' : '/dashboard';

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

            $context = $summary + ['weekLabel' => $weekLabel, 'dashboardUrl' => $dashboardUrl];
            $html = $this->twig->render($template, $context);

            if ($dryRun) {
                $io->writeln(sprintf('[dry-run] %s <%s> · %d sorties · %s km', $user->getUsername(), $email, $summary['runs'], $summary['km']));
                $sent++;
                continue;
            }

            $message = (new Email())
                ->from($from)
                ->to($email)
                ->subject(sprintf('Semaine du %s — ton résumé running 🏃', $weekLabel))
                ->html($html)
                ->text($this->buildTextFallback($summary, $dashboardUrl));

            try {
                $this->mailer->send($message);
                $sent++;
            } catch (TransportExceptionInterface $e) {
                $failed++;
                $io->warning(sprintf('Échec envoi à %s : %s', $email, $e->getMessage()));
            }
        }

        $io->success(sprintf('Résumé hebdo : %d envoyés, %d ignorés, %d échecs.', $sent, $skipped, $failed));

        return self::SUCCESS;
    }

    /** @param array<string,mixed> $s */
    private function buildTextFallback(array $s, string $dashboardUrl): string
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
        return implode("\n", $lines);
    }
}
