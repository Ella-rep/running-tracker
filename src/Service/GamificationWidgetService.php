<?php

namespace App\Service;

use App\Entity\AthleteStats;
use App\Entity\Gear;
use App\Entity\Quest;
use App\Entity\QuestProgress;
use App\Entity\RpgEvent;
use App\Entity\RunLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Calcule les données RPG à injecter dans le widget Twig.
 *
 * Modificateurs météo : lus depuis le champ `notes` du dernier RunLog
 * (valeurs clés : "chaleur", "canicule", "froid", "vent").
 * À terme : croiser avec MeteoService.
 */
class GamificationWidgetService
{
    public function __construct(private EntityManagerInterface $em) {}

    /**
     * Retourne le tableau rpg_data attendu par _gamification_widget.html.twig.
     *
     * @return array<string, mixed>
     */
    public function buildWidgetData(User $user): array
    {
        $stats = $this->getOrCreateStats($user);

        // ── Skills bruts ──────────────────────────────────────────────
        $speedBase     = $stats->getSkillSpeed();
        $enduranceBase = $stats->getSkillEndurance();
        $recoveryBase  = $stats->getSkillRecovery();

        // ── Modificateurs gear actifs ─────────────────────────────────
        $gears = $this->em->getRepository(Gear::class)
            ->findBy(['user' => $user, 'active' => true]);

        $gearModifiers = [];
        $speedMod = $enduranceMod = $recoveryMod = 0;

        foreach ($gears as $gear) {
            $delta = $gear->getModifier();
            match ($gear->getSkillType()) {
                'speed'     => $speedMod     += $delta,
                'endurance' => $enduranceMod += $delta,
                'recovery'  => $recoveryMod  += $delta,
                default     => null,
            };
            $sign = $delta >= 0 ? '+' : '';
            $gearModifiers[] = [
                'type'  => $delta >= 0 ? 'gear' : 'debuff',
                'label' => $gear->getName() . ' ' . $sign . $delta . ' ' . $this->skillLabel($gear->getSkillType()),
            ];
        }

        // ── Modificateurs météo (dernier RunLog) ──────────────────────
        $weatherModifiers = [];
        $lastLog = $this->em->getRepository(RunLog::class)
            ->findOneBy(['user' => $user], ['date' => 'DESC']);

        if ($lastLog) {
            $notes = strtolower((string) $lastLog->getNotes());
            if (str_contains($notes, 'canicule')) {
                $enduranceMod -= 9;
                $speedMod     -= 5;
                $weatherModifiers[] = ['type' => 'debuff', 'label' => '🔥 Canicule −9 endurance −5 vitesse'];
            } elseif (str_contains($notes, 'chaleur')) {
                $enduranceMod -= 6;
                $speedMod     -= 3;
                $weatherModifiers[] = ['type' => 'weather', 'label' => '☀️ Chaleur −6 endurance −3 vitesse'];
            }
            if (str_contains($notes, 'froid') || str_contains($notes, 'gel')) {
                $speedMod     -= 2;
                $weatherModifiers[] = ['type' => 'weather', 'label' => '❄️ Froid −2 vitesse'];
            }
            if (str_contains($notes, 'vent')) {
                $speedMod     -= 1;
                $weatherModifiers[] = ['type' => 'weather', 'label' => '💨 Vent −1 vitesse'];
            }
        }

        // ── Stats effectives ──────────────────────────────────────────
        $speedEff     = max(1, $speedBase + $speedMod);
        $enduranceEff = max(1, $enduranceBase + $enduranceMod);
        $recoveryEff  = max(1, $recoveryBase + $recoveryMod);

        // ── Quêtes actives ────────────────────────────────────────────
        $progresses = $this->em->getRepository(QuestProgress::class)
            ->findBy(['user' => $user, 'completed' => false], [], 10);

        $quests = [];
        foreach ($progresses as $progress) {
            $quest   = $progress->getQuest();
            $quests[] = [
                'type'             => $quest->getType(),
                'title'            => $quest->getTitle(),
                'subtitle'         => $quest->getSubtitle() ?? '',
                'xp_reward'        => $quest->getXpReward(),
                'progress_current' => $progress->getProgressCurrent(),
                'progress_max'     => $quest->getConditionValue(),
            ];
        }

        // ── Payload widget ────────────────────────────────────────────
        // ── Événements RPG non-acknowledged ──────────────────────
        $pendingEvents = $this->em->getRepository(RpgEvent::class)
            ->findBy(['user' => $user, 'acknowledged' => false], ['createdAt' => 'DESC'], 5);

        $events = array_map(fn(RpgEvent $e) => [
            'id'          => $e->getId(),
            'type'        => $e->getType(),
            'severity'    => $e->getSeverity(),
            'title'       => $e->getTitle(),
            'description' => $e->getDescription(),
            'icon'        => $e->getIcon(),
            'xp_delta'    => $e->getXpDelta(),
        ], $pendingEvents);

        return [
            'level'    => $stats->getLevel(),
            'xp'       => $stats->getXpInCurrentLevel(),
            'xp_next'  => $stats->getXpNeededForNextLevel(),
            'class'    => $stats->getRpgClass(),
            'avatar_emoji' => '🏃',
            'skills'   => [
                'speed'     => ['value' => $speedEff,     'delta' => $speedMod,     'delta_label' => $this->deltaLabel($speedMod)],
                'endurance' => ['value' => $enduranceEff, 'delta' => $enduranceMod, 'delta_label' => $this->deltaLabel($enduranceMod)],
                'recovery'  => ['value' => $recoveryEff,  'delta' => $recoveryMod,  'delta_label' => $this->deltaLabel($recoveryMod)],
            ],
            'modifiers' => array_merge($gearModifiers, $weatherModifiers),
            'quests'    => $quests,
            'events'    => $events,
        ];
    }

    /**
     * Recalcule les stats brutes d'après les RunLogs et persiste.
     * Appelé après chaque RunLog POST (à brancher dans RunLogProcessor).
     */
    public function recalculate(User $user): void
    {
        $stats = $this->getOrCreateStats($user);

        $logs = $this->em->getRepository(RunLog::class)
            ->findBy(['user' => $user], ['date' => 'DESC'], 20);

        if (empty($logs)) {
            $this->em->flush();
            return;
        }

        // Vitesse : basé sur allure moyenne des 5 dernières sorties
        $speedScore = $this->computeSpeedScore(array_slice($logs, 0, 5));
        // Endurance : volume km des 4 dernières semaines
        $enduranceScore = $this->computeEnduranceScore($logs);
        // Récupération : ratio sorties/repos
        $recoveryScore = $this->computeRecoveryScore($logs);

        $stats->setSkillSpeed($speedScore);
        $stats->setSkillEndurance($enduranceScore);
        $stats->setSkillRecovery($recoveryScore);

        $this->em->flush();
    }

    private function getOrCreateStats(User $user): AthleteStats
    {
        $stats = $this->em->getRepository(AthleteStats::class)->findOneBy(['user' => $user]);
        if (!$stats) {
            $stats = new AthleteStats();
            $stats->setUser($user);
            $this->em->persist($stats);
            $this->em->flush();
        }
        return $stats;
    }

    /**
     * Met à jour la progression des quêtes actives après un RunLog.
     * Gère : distance_km, pace_per_km, total_km, streak_days.
     */
    public function updateQuestProgress(User $user, RunLog $log): void
    {
        $progresses = $this->em->getRepository(QuestProgress::class)
            ->findBy(['user' => $user, 'completed' => false]);

        $stats = $this->getOrCreateStats($user);

        // ── XP de base par course ─────────────────────────────────────
        $km = (float)($log->getKm() ?? 0);
        $xpEarned = max(5, (int)round($km * 10));
        $stats->addXp($xpEarned);

        $runEvent = new RpgEvent();
        $runEvent->setUser($user)
            ->setType(RpgEvent::TYPE_BUFF)
            ->setSeverity(RpgEvent::SEV_INFO)
            ->setIcon('⚡')
            ->setTitle('Course enregistrée !')
            ->setDescription(sprintf('Tu gagnes %d XP pour %.1f km courus.', $xpEarned, $km))
            ->setXpDelta($xpEarned);
        $this->em->persist($runEvent);

        foreach ($progresses as $progress) {
            $quest = $progress->getQuest();
            $updated = false;

            switch ($quest->getConditionType()) {
                case Quest::CONDITION_DISTANCE_KM:
                    // Prend le max atteint en une seule sortie
                    $km = (float)($log->getKm() ?? 0);
                    if ($km > $progress->getProgressCurrent()) {
                        $progress->setProgressCurrent($km);
                        $updated = true;
                    }
                    break;

                case Quest::CONDITION_TOTAL_KM:
                    // Cumul de tous les logs
                    $total = $this->em->getRepository(RunLog::class)
                        ->createQueryBuilder('r')
                        ->select('SUM(r.km)')
                        ->where('r.user = :user')
                        ->setParameter('user', $user)
                        ->getQuery()
                        ->getSingleScalarResult() ?? 0;
                    $progress->setProgressCurrent((float)$total);
                    $updated = true;
                    break;

                case Quest::CONDITION_PACE:
                    // Amélioration d'allure : delta en secondes vs allure de référence
                    $paces = $this->em->getRepository(RunLog::class)
                        ->createQueryBuilder('r')
                        ->select('r.allure')
                        ->where('r.user = :user')
                        ->andWhere('r.allure IS NOT NULL')
                        ->orderBy('r.date', 'ASC')
                        ->setMaxResults(20)
                        ->setParameter('user', $user)
                        ->getQuery()
                        ->getScalarResult();
                    if (count($paces) >= 2) {
                        $first = $this->paceToSeconds($paces[0]['allure']);
                        $last  = $this->paceToSeconds($paces[count($paces)-1]['allure']);
                        if ($first && $last) {
                            $improvement = max(0, $first - $last);
                            $progress->setProgressCurrent((float)$improvement);
                            $updated = true;
                        }
                    }
                    break;
            }

            if ($updated && !$progress->isCompleted()) {
                if ($progress->getProgressCurrent() >= $quest->getConditionValue()) {
                    $progress->setCompleted(true);
                    $stats->addXp($quest->getXpReward());
                }
            }
        }

        $this->em->flush();
    }

    /**
     * Convertit une allure "MM:SS" en secondes par km.
     */
    private function paceToSeconds(?string $pace): ?int
    {
        if (!$pace || !preg_match('/^(\d+):(\d{2})$/', $pace, $m)) {
            return null;
        }
        return (int)$m[1] * 60 + (int)$m[2];
    }

    /**
     * Score vitesse 1-20 basé sur l'allure moyenne.
     * Référence : 9min/km = 10pts, 6min/km = 20pts, 12min/km = 5pts.
     */
    private function computeSpeedScore(array $logs): int
    {
        $paces = array_filter(array_map(
            fn(RunLog $l) => $this->paceToSeconds($l->getAllure()),
            $logs
        ));
        if (empty($paces)) { return 10; }
        $avgSec = array_sum($paces) / count($paces);
        // 540s = 9min/km → 10pts ; chaque 30s de mieux = +1pt
        $score = (int) round(10 + (540 - $avgSec) / 30);
        return max(1, min(20, $score));
    }

    /**
     * Score endurance 1-20 basé sur le volume km sur les 20 derniers logs.
     * Référence : 30km = 10pts, 60km = 20pts, 10km = 5pts.
     */
    private function computeEnduranceScore(array $logs): int
    {
        $totalKm = array_sum(array_map(fn(RunLog $l) => (float)($l->getKm() ?? 0), $logs));
        $score = (int) round(5 + ($totalKm / 3));
        return max(1, min(20, $score));
    }

    /**
     * Score récupération 1-20 : ratio effort perçu faible + jours depuis dernier log.
     */
    private function computeRecoveryScore(array $logs): int
    {
        $lastLog = $logs[0] ?? null;
        if (!$lastLog) { return 10; }
        $daysSinceLast = (new \DateTimeImmutable())->diff(
            new \DateTimeImmutable($lastLog->getDate())
        )->days;
        // 1 jour = 10pts, chaque jour de repos supplémentaire +1 (max 15)
        $score = min(15, 10 + max(0, $daysSinceLast - 1));
        // Effort perçu : 'facile'/'easy' = +2, 'difficile'/'hard' = -2
        $effort = strtolower((string)$lastLog->getPerceivedEffort());
        if (str_contains($effort, 'facile') || str_contains($effort, 'easy')) { $score += 2; }
        if (str_contains($effort, 'difficile') || str_contains($effort, 'hard') || str_contains($effort, 'épuisant')) { $score -= 2; }
        return max(1, min(20, $score));
    }

    private function skillLabel(string $type): string
    {
        return match ($type) {
            'speed'     => 'vitesse',
            'endurance' => 'endurance',
            'recovery'  => 'récup.',
            default     => $type,
        };
    }

    private function deltaLabel(int $delta): string
    {
        if ($delta === 0) { return ''; }
        return $delta > 0 ? 'buff actif' : 'debuff actif';
    }
}
