<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Définition d'une quête (partagée entre tous les users).
 * La progression individuelle est dans QuestProgress.
 *
 * type       : main | side | legend
 * conditionType : distance_km | pace_per_km | streak_days | total_km | bpm_ef
 * conditionValue : valeur cible (ex: 5.0 pour 5km, 8.5 pour 8min30/km)
 */
#[ORM\Entity]
#[ORM\Table(name: 'quest')]
class Quest
{
    public const TYPE_MAIN   = 'main';
    public const TYPE_SIDE   = 'side';
    public const TYPE_LEGEND = 'legend';

    public const CONDITION_DISTANCE_KM  = 'distance_km';
    public const CONDITION_PACE         = 'pace_per_km';
    public const CONDITION_STREAK       = 'streak_days';
    public const CONDITION_TOTAL_KM     = 'total_km';
    public const CONDITION_BPM_EF       = 'bpm_ef';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 30)]
    private string $type = self::TYPE_SIDE;

    #[ORM\Column(length: 180)]
    private string $title = '';

    /** Texte affiché sous la barre de progression */
    #[ORM\Column(length: 200, nullable: true)]
    private ?string $subtitle = null;

    #[ORM\Column(length: 40)]
    private string $conditionType = self::CONDITION_DISTANCE_KM;

    /** Valeur cible (km, allure en s/km, jours…) */
    #[ORM\Column(type: 'float')]
    private float $conditionValue = 0.0;

    #[ORM\Column]
    private int $xpReward = 100;

    /** Visible dans le picker ou interne (ex: quêtes système) */
    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getType(): string { return $this->type; }
    public function setType(string $t): static { $this->type = $t; return $this; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $t): static { $this->title = $t; return $this; }
    public function getSubtitle(): ?string { return $this->subtitle; }
    public function setSubtitle(?string $s): static { $this->subtitle = $s; return $this; }
    public function getConditionType(): string { return $this->conditionType; }
    public function setConditionType(string $c): static { $this->conditionType = $c; return $this; }
    public function getConditionValue(): float { return $this->conditionValue; }
    public function setConditionValue(float $v): static { $this->conditionValue = $v; return $this; }
    public function getXpReward(): int { return $this->xpReward; }
    public function setXpReward(int $x): static { $this->xpReward = $x; return $this; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $a): static { $this->active = $a; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
