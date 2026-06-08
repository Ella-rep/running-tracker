<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Repository\PlanDetailsRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
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
        new Get(uriTemplate: self::ITEM_URI, security: self::OWNER_SECURITY),
        new Put(uriTemplate: self::ITEM_URI, security: self::OWNER_SECURITY),
        new Patch(uriTemplate: self::ITEM_URI, security: self::OWNER_SECURITY),
        new Delete(uriTemplate: self::ITEM_URI, security: self::OWNER_SECURITY),
    ]
)]
#[ApiFilter(SearchFilter::class, properties: ['plan' => 'exact', 'position' => 'exact'])]
/**
 * Stores one scheduled session entry inside a training plan.
 */
class PlanDetails
{
    private const ITEM_URI = '/plan_details/{id}';
    private const OWNER_SECURITY = 'object.getUser() == user';
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

    #[ORM\Column(length: 16, nullable: true)]
    #[Groups(['plan_details:read', 'plan_details:write'])]
    private ?string $sessionType = null;

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

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['plan_details:read', 'plan_details:write'])]
    private bool $isCancelled = false;

    /**
     * Returns session identifier.
     */
    public function getId(): ?int { return $this->id; }

    /**
     * Returns owner user.
     */
    public function getUser(): User { return $this->user; }

    /**
     * Sets owner user.
     */
    public function setUser(User $user): static { $this->user = $user; return $this; }

    /**
     * Returns parent plan.
     */
    public function getPlan(): Plan { return $this->plan; }

    /**
     * Sets parent plan.
     */
    public function setPlan(Plan $plan): static { $this->plan = $plan; return $this; }

    /**
     * Returns parent plan name.
     */
    #[Groups(['plan_details:read'])]
    public function getPlanName(): string { return $this->plan->getName(); }

    /**
     * Returns session order inside plan.
     */
    public function getPosition(): int { return $this->position; }

    /**
     * Sets session order inside plan.
     */
    public function setPosition(int $position): static { $this->position = $position; return $this; }

    /**
     * Returns training week index.
     */
    public function getSem(): ?int { return $this->sem; }

    /**
     * Sets training week index.
     */
    public function setSem(?int $sem): static { $this->sem = $sem; return $this; }

    /**
     * Returns planned session date.
     */
    public function getSessionDate(): ?\DateTimeInterface { return $this->sessionDate; }

    /**
     * Sets planned session date.
     */
    public function setSessionDate(?\DateTimeInterface $sessionDate): static { $this->sessionDate = $sessionDate; return $this; }

    /**
     * Returns session format text.
     */
    public function getFormat(): string { return $this->format; }

    /**
     * Sets session format text.
     */
    public function setFormat(string $format): static { $this->format = $format; return $this; }

    /**
     * Returns normalized session type.
     */
    #[Groups(['plan_details:read', 'plan_details:write'])]
    #[SerializedName('sessionType')]
    public function getSessionType(): ?string { return $this->sessionType; }

    /**
     * Sets normalized session type.
     */
    public function setSessionType(?string $sessionType): static { $this->sessionType = $sessionType; return $this; }

    /**
     * Returns perceived effort marker.
     */
    public function getPe(): ?string { return $this->pe; }

    /**
     * Sets perceived effort marker.
     */
    public function setPe(?string $pe): static { $this->pe = $pe; return $this; }

    /**
     * Returns total session duration in minutes.
     */
    public function getTotalMin(): ?int { return $this->totalMin; }

    /**
     * Sets total session duration in minutes.
     */
    public function setTotalMin(?int $totalMin): static { $this->totalMin = $totalMin; return $this; }

    /**
     * Returns whether the session is optional.
     */
    #[Groups(['plan_details:read', 'plan_details:write'])]
    #[SerializedName('isOptional')]
    public function isOptional(): bool { return $this->isOptional; }

    /**
     * Sets optional flag.
     */
    public function setIsOptional(bool $isOptional): static { $this->isOptional = $isOptional; return $this; }

    /**
     * Sets optional flag (alias for serializer compatibility).
     */
    public function setOptional(bool $optional): static { $this->isOptional = $optional; return $this; }

    /**
     * Returns whether the session is completed.
     */
    #[Groups(['plan_details:read', 'plan_details:write'])]
    #[SerializedName('isDone')]
    public function isDone(): bool { return $this->isDone; }

    /**
     * Sets completion flag (alias for serializer compatibility).
     */
    public function setDone(bool $done): static { $this->isDone = $done; return $this; }

    /**
     * Returns whether the session is cancelled (deliberately skipped).
     */
    #[Groups(['plan_details:read', 'plan_details:write'])]
    #[SerializedName('isCancelled')]
    public function isCancelled(): bool { return $this->isCancelled; }

    /**
     * Sets cancelled flag.
     */
    public function setIsCancelled(bool $isCancelled): static { $this->isCancelled = $isCancelled; return $this; }
}
