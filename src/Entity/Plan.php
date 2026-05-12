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
        new Get(uriTemplate: '/plans/{id}', security: 'object.getUser() == user'),
        new Put(uriTemplate: '/plans/{id}', security: 'object.getUser() == user'),
        new Delete(uriTemplate: '/plans/{id}', security: 'object.getUser() == user'),
    ]
)]
#[ApiFilter(SearchFilter::class, properties: ['name' => 'exact'])]
class Plan
{
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

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function setUser(User $user): static { $this->user = $user; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }
}
