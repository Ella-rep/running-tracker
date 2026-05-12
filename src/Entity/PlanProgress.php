<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Repository\PlanProgressRepository;
use App\State\PlanProgressProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PlanProgressRepository::class)]
#[ORM\Table(name: 'plan_progress')]
#[ORM\UniqueConstraint(columns: ['user_id', 'plan_key', 'session_index'])]
#[ApiResource(
    normalizationContext: ['groups' => ['progress:read']],
    denormalizationContext: ['groups' => ['progress:write']],
    security: 'is_granted("ROLE_USER")',
    operations: [
        new GetCollection(uriTemplate: '/plan_progresses'),
        new Post(uriTemplate: '/plan_progresses', processor: PlanProgressProcessor::class),
    ]
)]
class PlanProgress
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    #[Groups(['progress:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'planProgresses')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 32)]
    #[Assert\NotBlank]
    #[Groups(['progress:read', 'progress:write'])]
    private string $planKey = '';

    #[ORM\Column]
    #[Assert\GreaterThanOrEqual(0)]
    #[Groups(['progress:read', 'progress:write'])]
    private int $sessionIndex = 0;

    #[ORM\Column]
    #[Groups(['progress:read', 'progress:write'])]
    private bool $done = false;

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function setUser(User $u): static { $this->user = $u; return $this; }
    public function getPlanKey(): string { return $this->planKey; }
    public function setPlanKey(string $k): static { $this->planKey = $k; return $this; }
    public function getSessionIndex(): int { return $this->sessionIndex; }
    public function setSessionIndex(int $i): static { $this->sessionIndex = $i; return $this; }
    public function isDone(): bool { return $this->done; }
    public function setDone(bool $d): static { $this->done = $d; return $this; }
}
