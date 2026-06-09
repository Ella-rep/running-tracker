<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\HealthPauseRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: HealthPauseRepository::class)]
#[ORM\Table(name: 'health_pauses')]
#[ORM\UniqueConstraint(name: 'uniq_health_pause_user', columns: ['user_id'])]
class HealthPause
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class, inversedBy: 'healthPause')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(type: 'string', length: 10)]
    private string $startedAt = '';

    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $type = null;

    #[ORM\Column(nullable: true)]
    private ?int $estimatedDays = null;

    #[ORM\Column(type: 'string', length: 10, nullable: true)]
    private ?string $resumedAt = null;

    public function getId(): ?int { return $this->id; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }

    public function getStartedAt(): string { return $this->startedAt; }
    public function setStartedAt(string $startedAt): static { $this->startedAt = $startedAt; return $this; }

    public function getType(): ?string { return $this->type; }
    public function setType(?string $type): static { $this->type = $type; return $this; }

    public function getEstimatedDays(): ?int { return $this->estimatedDays; }
    public function setEstimatedDays(?int $estimatedDays): static { $this->estimatedDays = $estimatedDays; return $this; }

    public function getResumedAt(): ?string { return $this->resumedAt; }
    public function setResumedAt(?string $resumedAt): static { $this->resumedAt = $resumedAt; return $this; }

    public function isActive(): bool
    {
        return $this->resumedAt === null;
    }

    public function isInRecoveryWindow(int $dayThreshold = 7): bool
    {
        if ($this->resumedAt === null) {
            return false;
        }

        $resumed = \DateTimeImmutable::createFromFormat('Y-m-d', $this->resumedAt);
        if (!$resumed instanceof \DateTimeImmutable) {
            return false;
        }

        $today = new \DateTimeImmutable('today');
        $daysSinceResume = (int) $resumed->diff($today)->format('%a');

        return $daysSinceResume < $dayThreshold;
    }

    public function getRecoveryDaysRemaining(int $dayThreshold = 7): int
    {
        if ($this->resumedAt === null) {
            return 0;
        }

        $resumed = \DateTimeImmutable::createFromFormat('Y-m-d', $this->resumedAt);
        if (!$resumed instanceof \DateTimeImmutable) {
            return 0;
        }

        $today = new \DateTimeImmutable('today');
        $daysSinceResume = (int) $resumed->diff($today)->format('%a');
        $remaining = $dayThreshold - $daysSinceResume;

        return max(0, $remaining);
    }
}
