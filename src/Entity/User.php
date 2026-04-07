<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ApiResource(
    operations: [
        new Get(uriTemplate: '/auth/me', security: 'is_granted("ROLE_USER")', normalizationContext: ['groups' => ['user:read']]),
        new Post(uriTemplate: '/auth/register', normalizationContext: ['groups' => ['user:read']], denormalizationContext: ['groups' => ['user:write']], security: 'not is_granted("ROLE_USER")'),
    ],
    routePrefix: '/api'
)]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    #[Groups(['user:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 64)]
    #[Groups(['user:read', 'user:write'])]
    private string $username = '';

    #[ORM\Column]
    private string $password = '';

    #[Assert\NotBlank]
    #[Assert\Length(min: 6)]
    #[Groups(['user:write'])]
    private ?string $plainPassword = null;

    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    #[Groups(['user:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\OneToMany(targetEntity: RunLog::class, mappedBy: 'user', cascade: ['remove'])]
    private Collection $runLogs;

    #[ORM\OneToMany(targetEntity: Race::class, mappedBy: 'user', cascade: ['remove'])]
    private Collection $races;

    #[ORM\OneToMany(targetEntity: PlanCheck::class, mappedBy: 'user', cascade: ['remove'])]
    private Collection $planChecks;

    public function __construct()
    {
        $this->createdAt  = new \DateTimeImmutable();
        $this->runLogs    = new ArrayCollection();
        $this->races      = new ArrayCollection();
        $this->planChecks = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getUsername(): string { return $this->username; }
    public function setUsername(string $u): static { $this->username = $u; return $this; }
    public function getUserIdentifier(): string { return $this->username; }
    public function getPassword(): string { return $this->password; }
    public function setPassword(string $p): static { $this->password = $p; return $this; }
    public function getPlainPassword(): ?string { return $this->plainPassword; }
    public function setPlainPassword(?string $p): static { $this->plainPassword = $p; return $this; }
    public function getRoles(): array { return array_unique([...$this->roles, 'ROLE_USER']); }
    public function setRoles(array $r): static { $this->roles = $r; return $this; }
    public function eraseCredentials(): void { $this->plainPassword = null; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
