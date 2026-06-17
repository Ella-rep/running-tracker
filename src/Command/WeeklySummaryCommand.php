<?php

namespace App\Command;

use App\Service\WeeklySummaryMailer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sends the weekly running digest. Intended to run via cron on Monday morning:
 *   0 7 * * 1   bin/console app:weekly-summary
 * Delegates the actual send to WeeklySummaryMailer, which is also used by the
 * on-demand admin maintenance action.
 */
#[AsCommand(name: 'app:weekly-summary', description: 'Envoie le résumé running hebdomadaire par mail.')]
final class WeeklySummaryCommand extends Command
{
    public function __construct(
        private WeeklySummaryMailer $weeklySummaryMailer,
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

        $result = $this->weeklySummaryMailer->sendAll(null, (bool) $input->getOption('dry-run'));

        $io->success(sprintf(
            'Résumé hebdo : %d envoyés, %d ignorés, %d échecs.',
            $result['sent'],
            $result['skipped'],
            $result['failed'],
        ));

        return self::SUCCESS;
    }
}
