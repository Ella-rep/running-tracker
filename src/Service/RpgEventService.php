<?php

namespace App\Service;

use App\Entity\AthleteStats;
use App\Entity\Race;
use App\Entity\RpgEvent;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Génère et persiste les événements RPG (malédictions, buffs…).
 * Appelé depuis RaceProcessor après chaque sauvegarde de course.
 */
class RpgEventService
{
    /** Malédictions pour course annulée par l'organisateur */
    private const CURSES_CANCELLED = [
        [
            'title' => 'Malédiction du Parchemin Déchiré',
            'description' => "Les dieux du calendrier ont tranché : cette course n'aura pas lieu. Ton inscription est maudite. Que ce DNF organisationnel ne ternisse pas ta légende, coureur.",
            'icon' => '📜',
            'xp' => 0,
        ],
        [
            'title' => 'L\'Oracle a Parlé : Course Annulée',
            'description' => "Un sortilège administratif a frappé ton parcours avant même le départ. La faute incombe aux organisateurs, pas à tes jambes. Tu renaîtras sur une autre ligne de départ.",
            'icon' => '🔮',
            'xp' => 0,
        ],
        [
            'title' => 'Le Destin a Rayé Ton Dossard',
            'description' => "Forces incontrôlables ont effacé cette course de ta chronique. Mais un vrai ranger ne perd pas d'XP pour les batailles que d'autres ont annulées.",
            'icon' => '🗺️',
            'xp' => 0,
        ],
    ];

    /** Malédictions pour DNS (Did Not Start — choix du coureur) */
    private const CURSES_DNS = [
        [
            'title' => 'Malédiction du Départ Raté',
            'description' => "La ligne de départ t'a appelé… mais tu n'es pas venu. Peut-être une sage décision, peut-être une ombre sur ton blason. Le sort te rattrapera.",
            'icon' => '⏳',
            'xp' => -20,
        ],
        [
            'title' => 'Le Sort de la Jambe Lourde',
            'description' => "Tes bottes étaient de plomb ce jour-là. Le DNS hante les chroniques, mais les vrais guerriers savent quand ne pas combattre.",
            'icon' => '🦶',
            'xp' => -20,
        ],
        [
            'title' => 'L\'Ombre du Non-Départ',
            'description' => "Tu as regardé la ligne de départ sans la franchir. −20 XP de honte, mais +10 sagesse si c'était pour te protéger.",
            'icon' => '👁️',
            'xp' => -20,
        ],
    ];

    /** Malédictions pour DNF (Did Not Finish) */
    private const CURSES_DNF = [
        [
            'title' => 'La Malédiction du Kilomètre Maudit',
            'description' => "Tu as combattu vaillamment, mais les forces obscures t'ont arrêté avant la ligne d'arrivée. Un DNF n'est pas une défaite — c'est un chapitre inachevé.",
            'icon' => '🩹',
            'xp' => -10,
        ],
        [
            'title' => 'Le Sortilège de l\'Abandon',
            'description' => "Le chemin s'est refermé sur toi. Mais chaque DNF forge une armure plus résistante pour la prochaine bataille.",
            'icon' => '⚡',
            'xp' => -10,
        ],
        [
            'title' => 'Malédiction du Dernier Ravitaillement',
            'description' => "Les jambes ont cédé avant la gloire. −10 XP, mais ta quête principale reste ouverte. Les héros ne finissent pas toujours au premier essai.",
            'icon' => '🏳️',
            'xp' => -10,
        ],
    ];

    public function __construct(private EntityManagerInterface $em) {}

    /**
     * Évalue une race et génère l'événement RPG approprié.
     * Appelé depuis RaceProcessor après flush.
     */
    public function processRace(Race $race, User $user): ?RpgEvent
    {
        if (!$user->isDashboardGamificationVisible()) {
            return null;
        }

        $status = $race->getDnfStatus();

        $event = match ($status) {
            'cancelled' => $this->createCurse($user, $race, self::CURSES_CANCELLED, RpgEvent::SEV_WARNING),
            'dns'       => $this->createCurse($user, $race, self::CURSES_DNS, RpgEvent::SEV_DANGER),
            'dnf'       => $this->createCurse($user, $race, self::CURSES_DNF, RpgEvent::SEV_DANGER),
            default     => null,
        };

        if ($event) {
            // Applique le malus XP sur AthleteStats
            if ($event->getXpDelta() !== 0) {
                $stats = $this->em->getRepository(AthleteStats::class)->findOneBy(['user' => $user]);
                if ($stats) {
                    $stats->addXp($event->getXpDelta());
                }
            }
            $this->em->persist($event);
            $this->em->flush();
        }

        return $event;
    }

    /**
     * Retourne les événements non-acknowledged pour le widget.
     *
     * @return RpgEvent[]
     */
    public function getPendingEvents(User $user): array
    {
        return $this->em->getRepository(RpgEvent::class)->findBy(
            ['user' => $user, 'acknowledged' => false],
            ['createdAt' => 'DESC'],
            5
        );
    }

    private function createCurse(User $user, Race $race, array $pool, string $severity): RpgEvent
    {
        $template = $pool[array_rand($pool)];

        $event = new RpgEvent();
        $event->setUser($user);
        $event->setType(RpgEvent::TYPE_CURSE);
        $event->setSeverity($severity);
        $event->setTitle($template['title']);
        $event->setDescription($template['description']);
        $event->setIcon($template['icon']);
        $event->setXpDelta($template['xp']);
        $event->setRace($race);

        return $event;
    }
}
