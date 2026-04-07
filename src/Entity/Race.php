<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use App\Repository\RaceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: RaceRepository::class)]
#[ORM\Table(name: 'races')]
#[ApiResource(
    routePrefix: '/api',
    normalizationContext: ['groups' => ['race:read']],
    denormalizationContext: ['groups' => ['race:write']],
    security: 'is_granted("ROLE_USER")',
    operations: [
        new GetCollection(),
        new Post(),
        new Get(security: 'object.getUser() == user'),
        new Put(security: 'object.getUser() == user'),
        new Delete(security: 'object.getUser() == user'),
    ]
)]
#[ApiFilter(OrderFilter::class, properties: ['date' => 'ASC'])]
class Race
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    #[Groups(['race:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'races')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 128)]
    #[Assert\NotBlank]
    #[Groups(['race:read', 'race:write'])]
    private string $name = '';

    #[ORM\Column(length: 10)]
    #[Assert\NotBlank]
    #[Assert\Date]
    #[Groups(['race:read', 'race:write'])]
    private string $date = '';

    #[ORM\Column(length: 16, nullable: true)]
    #[Groups(['race:read', 'race:write'])]
    private ?string $distance = null;

    #[ORM\Column(length: 12, nullable: true)]
    #[Groups(['race:read', 'race:write'])]
    private ?string $objective = null;

    #[ORM\Column(length: 12, nullable: true)]
    #[Groups(['race:read', 'race:write'])]
    private ?string $result = null;

    #[ORM\Column]
    #[Groups(['race:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct() { $this->createdAt = new \DateTimeImmutable(); }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function setUser(User $u): static { $this->user = $u; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $n): static { $this->name = $n; return $this; }
    public function getDate(): string { return $this->date; }
    public function setDate(string $d): static { $this->date = $d; return $this; }
    public function getDistance(): ?string { return $this->distance; }
    public function setDistance(?string $d): static { $this->distance = $d; return $this; }
    public function getObjective(): ?string { return $this->objective; }
    public function setObjective(?string $o): static { $this->objective = $o; return $this; }
    public function getResult(): ?string { return $this->result; }
    public function setResult(?string $r): static { $this->result = $r; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
