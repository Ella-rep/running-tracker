<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Repository\PlanDetailsRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PlanDetailsRepository::class)]
#[ORM\Table(name: 'plan_details')]
#[ORM\UniqueConstraint(name: 'uniq_plan_details_user_plan_pos', columns: ['user_id', 'plan_id', 'position'])]
#[ApiResource(
    normalizationContext: ['groups' => ['plan_details:read']],
    denormalizationContext: ['groups' => ['plan_details:write']],
    security: 'is_granted("ROLE_USER")',
    operations: [
        new GetCollection(uriTemplate: '/plan_details'),
        new Post(uriTemplate: '/plan_details'),
        new Get(uriTemplate: '/plan_details/{id}', security: 'object.getUser() == user'),
        new Put(uriTemplate: '/plan_details/{id}', security: 'object.getUser() == user'),
        new Delete(uriTemplate: '/plan_details/{id}', security: 'object.getUser() == user'),
    ]
)]
#[ApiFilter(SearchFilter::class, properties: ['plan' => 'exact', 'position' => 'exact'])]
class PlanDetails
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    #[Groups(['plan_details:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Plan::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['plan_details:read', 'plan_details:write'])]
    private Plan $plan;

    #[ORM\Column]
    #[Assert\GreaterThan(0)]
    #[Groups(['plan_details:read', 'plan_details:write'])]
    private int $position = 1;

    #[ORM\Column(nullable: true)]
    #[Groups(['plan_details:read', 'plan_details:write'])]
    private ?int $sem = null;

    #[ORM\Column(type: 'date', nullable: true)]
    #[Groups(['plan_details:read', 'plan_details:write'])]
    private ?\DateTimeInterface $sessionDate = null;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    #[Groups(['plan_details:read', 'plan_details:write'])]
    private string $format = '';

    #[ORM\Column(length: 10, nullable: true)]
    #[Groups(['plan_details:read', 'plan_details:write'])]
    private ?string $pe = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['plan_details:read', 'plan_details:write'])]
    private ?int $totalMin = null;

    #[ORM\Column]
    #[Groups(['plan_details:read', 'plan_details:write'])]
    private bool $isOptional = false;

    #[ORM\Column]
    #[Groups(['plan_details:read', 'plan_details:write'])]
    private bool $isDone = false;

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function setUser(User $user): static { $this->user = $user; return $this; }
    public function getPlan(): Plan { return $this->plan; }
    public function setPlan(Plan $plan): static { $this->plan = $plan; return $this; }
    #[Groups(['plan_details:read'])]
    public function getPlanName(): string { return $this->plan->getName(); }
    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): static { $this->position = $position; return $this; }
    public function getSem(): ?int { return $this->sem; }
    public function setSem(?int $sem): static { $this->sem = $sem; return $this; }
    public function getSessionDate(): ?\DateTimeInterface { return $this->sessionDate; }
    public function setSessionDate(?\DateTimeInterface $sessionDate): static { $this->sessionDate = $sessionDate; return $this; }
    public function getFormat(): string { return $this->format; }
    public function setFormat(string $format): static { $this->format = $format; return $this; }
    public function getPe(): ?string { return $this->pe; }
    public function setPe(?string $pe): static { $this->pe = $pe; return $this; }
    public function getTotalMin(): ?int { return $this->totalMin; }
    public function setTotalMin(?int $totalMin): static { $this->totalMin = $totalMin; return $this; }
    public function isOptional(): bool { return $this->isOptional; }
    public function setIsOptional(bool $isOptional): static { $this->isOptional = $isOptional; return $this; }
    public function isDone(): bool { return $this->isDone; }
    public function setIsDone(bool $isDone): static { $this->isDone = $isDone; return $this; }
}
