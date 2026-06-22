<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity]
#[ORM\Table(name: 'gear')]
class Gear
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 120)]
    private string $name = '';

    /** speed / endurance / recovery */
    #[ORM\Column(length: 30)]
    private string $skillType = 'speed';

    /** Positive = buff, negative = debuff */
    #[ORM\Column]
    private int $modifier = 0;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $retiredAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function setUser(User $u): static { $this->user = $u; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $n): static { $this->name = $n; return $this; }
    public function getSkillType(): string { return $this->skillType; }
    public function setSkillType(string $t): static { $this->skillType = $t; return $this; }
    public function getModifier(): int { return $this->modifier; }
    public function setModifier(int $m): static { $this->modifier = $m; return $this; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $a): static { $this->active = $a; return $this; }
    public function getRetiredAt(): ?\DateTimeImmutable { return $this->retiredAt; }
    public function setRetiredAt(?\DateTimeImmutable $d): static { $this->retiredAt = $d; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
