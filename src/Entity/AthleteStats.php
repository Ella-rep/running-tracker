<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Snapshot des stats de gamification d'un user.
 * Un seul enregistrement par user (OneToOne).
 * Recalculé par GamificationWidgetService après chaque RunLog.
 *
 * Skills bruts (avant modificateurs gear/météo) :
 *   speed     : basé sur l'allure moyenne récente
 *   endurance : basé sur le volume km et BPM EF
 *   recovery  : basé sur les jours de repos et perceived effort
 *
 * XP : cumulé, level calculé à la volée (voir getLevelFromXp)
 */
#[ORM\Entity]
#[ORM\Table(name: 'athlete_stats')]
class AthleteStats
{
    /** XP nécessaire par palier de niveau (index = level-1) */
    private const LEVEL_THRESHOLDS = [0, 100, 250, 500, 900, 1500, 2300, 3400, 5000, 7000, 10000];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(options: ['default' => 0])]
    private int $xpTotal = 0;

    #[ORM\Column(options: ['default' => 10])]
    private int $skillSpeed = 10;

    #[ORM\Column(options: ['default' => 10])]
    private int $skillEndurance = 10;

    #[ORM\Column(options: ['default' => 10])]
    private int $skillRecovery = 10;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function setUser(User $u): static { $this->user = $u; return $this; }

    public function getXpTotal(): int { return $this->xpTotal; }
    public function addXp(int $xp): static { $this->xpTotal += $xp; $this->touch(); return $this; }
    public function setXpTotal(int $x): static { $this->xpTotal = $x; return $this; }

    public function getSkillSpeed(): int { return $this->skillSpeed; }
    public function setSkillSpeed(int $v): static { $this->skillSpeed = $v; return $this; }
    public function getSkillEndurance(): int { return $this->skillEndurance; }
    public function setSkillEndurance(int $v): static { $this->skillEndurance = $v; return $this; }
    public function getSkillRecovery(): int { return $this->skillRecovery; }
    public function setSkillRecovery(int $v): static { $this->skillRecovery = $v; return $this; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    private function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }

    public function getLevel(): int
    {
        $level = 1;
        foreach (self::LEVEL_THRESHOLDS as $i => $threshold) {
            if ($this->xpTotal >= $threshold) {
                $level = $i + 1;
            } else {
                break;
            }
        }
        return $level;
    }

    public function getXpForCurrentLevel(): int
    {
        $level = $this->getLevel();
        return self::LEVEL_THRESHOLDS[$level - 1] ?? 0;
    }

    public function getXpForNextLevel(): int
    {
        $level = $this->getLevel();
        return self::LEVEL_THRESHOLDS[$level] ?? self::LEVEL_THRESHOLDS[array_key_last(self::LEVEL_THRESHOLDS)];
    }

    public function getXpInCurrentLevel(): int
    {
        return $this->xpTotal - $this->getXpForCurrentLevel();
    }

    public function getXpNeededForNextLevel(): int
    {
        return $this->getXpForNextLevel() - $this->getXpForCurrentLevel();
    }

    /** Classe RPG selon le niveau */
    public function getRpgClass(): string
    {
        return match (true) {
            $this->getLevel() >= 10 => 'Légende',
            $this->getLevel() >= 7  => 'Halfling Coureur',
            $this->getLevel() >= 5  => 'Ranger',
            $this->getLevel() >= 3  => 'Apprenti',
            default                 => 'Recrue',
        };
    }
}
