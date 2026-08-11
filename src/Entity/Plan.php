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
use App\Repository\PlanRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PlanRepository::class)]
#[ORM\Table(name: 'plans')]
#[ORM\UniqueConstraint(name: 'uniq_plans_user_name', columns: ['user_id', 'name'])]
#[ApiResource(
    normalizationContext: ['groups' => ['plan:read']],
    denormalizationContext: ['groups' => ['plan:write']],
    security: 'is_granted("ROLE_USER")',
    operations: [
        new GetCollection(uriTemplate: '/plans'),
        new Post(uriTemplate: '/plans', processor: 'App\\State\\PlanProcessor'),
        new Get(uriTemplate: self::PLAN_ITEM_URI, security: self::OWNER_SECURITY),
        new Put(uriTemplate: self::PLAN_ITEM_URI, security: self::OWNER_SECURITY, processor: 'App\\State\\PlanPutProcessor'),
        new Patch(
            uriTemplate: '/plans/{id}/sessions',
            input: 'App\\ApiResource\\PlanSessionsReplaceInput',
            processor: 'App\\State\\PlanSessionsReplaceProcessor',
            read: false,
            deserialize: true,
            security: 'is_granted("ROLE_USER")'
        ),
        new Delete(
            uriTemplate: self::PLAN_ITEM_URI,
            security: self::OWNER_SECURITY,
            processor: 'App\\State\\PlanDeleteProcessor'
        ),
    ]
)]
#[ApiFilter(SearchFilter::class, properties: ['name' => 'exact'])]
/**
 * Training plan owned by a user.
 */
class Plan
{
    private const PLAN_ITEM_URI = '/plans/{id}';
    private const OWNER_SECURITY = 'object.getUser() == user';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    #[Groups(['plan:read', 'plan_details:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 64)]
    #[Assert\NotBlank]
    #[Groups(['plan:read', 'plan:write', 'plan_details:read'])]
    private string $name = '';

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['plan:read', 'plan:write'])]
    private bool $dashboardTracked = true;

    /**
     * Planning mode: 'dated' (sessions have dates) or 'ordered' (sessions ordered by position only).
     */
    #[ORM\Column(length: 10, options: ['default' => 'dated'])]
    #[Assert\Choice(choices: ['dated', 'ordered'])]
    #[Groups(['plan:read', 'plan:write'])]
    private string $mode = 'dated';

    /**
     * Whether the user manually marked this plan as completed (independent of
     * per-session validation).
     */
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['plan:read', 'plan:write'])]
    private bool $isCompleted = false;

    /**
     * Whether the plan is archived: read-only (no more edits to metadata or
     * sessions) but does not touch session completion or the manual
     * completion flag.
     */
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['plan:read', 'plan:write'])]
    private bool $isArchived = false;

    /**
     * Returns the plan identifier.
     */
    public function getId(): ?int { return $this->id; }

    /**
     * Returns the owner of the plan.
     */
    public function getUser(): User { return $this->user; }

    /**
     * Sets the owner of the plan.
     */
    public function setUser(User $user): static { $this->user = $user; return $this; }

    /**
     * Returns the display name of the plan.
     */
    public function getName(): string { return $this->name; }

    /**
     * Sets the display name of the plan.
     */
    public function setName(string $name): static { $this->name = $name; return $this; }

    /**
     * Returns whether the plan is tracked on dashboard widgets.
     */
    public function isDashboardTracked(): bool { return $this->dashboardTracked; }

    /**
     * Sets whether the plan is tracked on dashboard widgets.
     */
    public function setDashboardTracked(bool $dashboardTracked): static { $this->dashboardTracked = $dashboardTracked; return $this; }

    /**
     * Returns the planning mode ('dated' or 'ordered').
     */
    public function getMode(): string { return $this->mode; }

    /**
     * Sets the planning mode ('dated' or 'ordered').
     */
    public function setMode(string $mode): static { $this->mode = $mode; return $this; }

    /**
     * Returns whether the plan was manually marked as completed.
     */
    public function isCompleted(): bool { return $this->isCompleted; }

    /**
     * Sets the manual completion flag.
     */
    public function setIsCompleted(bool $isCompleted): static { $this->isCompleted = $isCompleted; return $this; }

    /**
     * Returns whether the plan is archived (read-only).
     */
    public function isArchived(): bool { return $this->isArchived; }

    /**
     * Sets the archived flag.
     */
    public function setIsArchived(bool $isArchived): static { $this->isArchived = $isArchived; return $this; }
}
