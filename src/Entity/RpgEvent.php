<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Événement RPG lié à un user : malédiction, buff, quête annulée…
 *
 * type      : curse | buff | quest_cancelled | quest_completed | random
 * severity  : info | warning | danger | legendary
 */
#[ORM\Entity]
#[ORM\Table(name: 'rpg_event')]
class RpgEvent
{
    public const TYPE_CURSE           = 'curse';
    public const TYPE_BUFF            = 'buff';
    public const TYPE_QUEST_CANCELLED = 'quest_cancelled';
    public const TYPE_QUEST_COMPLETED = 'quest_completed';
    public const TYPE_RANDOM          = 'random';

    public const SEV_INFO      = 'info';
    public const SEV_WARNING   = 'warning';
    public const SEV_DANGER    = 'danger';
    public const SEV_LEGENDARY = 'legendary';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 30)]
    private string $type = self::TYPE_RANDOM;

    #[ORM\Column(length: 20)]
    private string $severity = self::SEV_INFO;

    /** Titre court style RPG (ex: "Malédiction du Chrono Brisé") */
    #[ORM\Column(length: 180)]
    private string $title = '';

    /** Description RPG narrative */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /** Emoji/icône affiché dans le widget */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $icon = null;

    /** XP malus (négatif) ou bonus (positif) appliqué */
    #[ORM\Column(options: ['default' => 0])]
    private int $xpDelta = 0;

    /** Référence optionnelle à une course (Race) */
    #[ORM\ManyToOne(targetEntity: Race::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Race $race = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $acknowledged = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function setUser(User $u): static { $this->user = $u; return $this; }
    public function getType(): string { return $this->type; }
    public function setType(string $t): static { $this->type = $t; return $this; }
    public function getSeverity(): string { return $this->severity; }
    public function setSeverity(string $s): static { $this->severity = $s; return $this; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $t): static { $this->title = $t; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): static { $this->description = $d; return $this; }
    public function getIcon(): ?string { return $this->icon; }
    public function setIcon(?string $i): static { $this->icon = $i; return $this; }
    public function getXpDelta(): int { return $this->xpDelta; }
    public function setXpDelta(int $x): static { $this->xpDelta = $x; return $this; }
    public function getRace(): ?Race { return $this->race; }
    public function setRace(?Race $r): static { $this->race = $r; return $this; }
    public function isAcknowledged(): bool { return $this->acknowledged; }
    public function setAcknowledged(bool $a): static { $this->acknowledged = $a; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
