<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Progression d'un user sur une quête donnée.
 * progressCurrent : valeur atteinte (km courus, allure max, streak…)
 */
#[ORM\Entity]
#[ORM\Table(name: 'quest_progress')]
#[ORM\UniqueConstraint(name: 'uq_user_quest', columns: ['user_id', 'quest_id'])]
class QuestProgress
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Quest::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Quest $quest;

    #[ORM\Column(type: 'float', options: ['default' => 0])]
    private float $progressCurrent = 0.0;

    #[ORM\Column(options: ['default' => false])]
    private bool $completed = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function setUser(User $u): static { $this->user = $u; return $this; }
    public function getQuest(): Quest { return $this->quest; }
    public function setQuest(Quest $q): static { $this->quest = $q; return $this; }
    public function getProgressCurrent(): float { return $this->progressCurrent; }
    public function setProgressCurrent(float $v): static { $this->progressCurrent = $v; $this->updatedAt = new \DateTimeImmutable(); return $this; }
    public function isCompleted(): bool { return $this->completed; }
    public function setCompleted(bool $c): static { $this->completed = $c; if ($c && $this->completedAt === null) { $this->completedAt = new \DateTimeImmutable(); } return $this; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** Pourcentage 0-100 */
    public function getPercent(): int
    {
        $max = $this->quest->getConditionValue();
        if ($max <= 0) { return 0; }
        return (int) min(100, round(($this->progressCurrent / $max) * 100));
    }
}
