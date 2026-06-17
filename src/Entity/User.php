<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use App\Entity\DashboardWidgetKeys;
use App\Entity\HealthPause;
use App\Controller\AuthLoginController;
use App\Controller\AuthMeController;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[UniqueEntity(fields: ['username'], message: "Ce nom d'utilisateur est déjà utilisé.")]
#[UniqueEntity(fields: ['email'], message: 'Cette adresse email est déjà utilisée.')]
#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/auth/me',
            controller: AuthMeController::class,
            read: false,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => ['user:read']]
        ),
        new Post(
            uriTemplate: '/auth/login',
            controller: AuthLoginController::class,
            read: false,
            deserialize: false,
            openapiContext: [
                'summary' => 'Connexion utilisateur',
                'description' => 'Authentifie un utilisateur avec email et mot de passe puis retourne un JWT.',
                'requestBody' => [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'required' => ['email', 'password'],
                                'properties' => [
                                    'email' => ['type' => 'string', 'format' => 'email', 'example' => 'user@example.com'],
                                    'password' => ['type' => 'string', 'example' => 'secret123'],
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        ),
        new Post(uriTemplate: '/auth/register', normalizationContext: ['groups' => ['user:read']], denormalizationContext: ['groups' => ['user:write']], security: 'not is_granted("ROLE_USER")'),
    ]
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

    #[ORM\Column(length: 180, unique: true, nullable: true)]
    #[Assert\NotBlank(groups: ['Default'])]
    #[Assert\Email]
    #[Groups(['user:read', 'user:write'])]
    private ?string $email = null;

    #[ORM\Column]
    private string $password = '';

    #[Assert\NotBlank]
    #[Assert\Length(min: 6)]
    #[Groups(['user:write'])]
    private ?string $plainPassword = null;

    #[ORM\Column]
    #[Groups(['user:read'])]
    private array $roles = [];

    #[ORM\Column]
    #[Groups(['user:read'])]
    private \DateTimeImmutable $createdAt;
    
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $resetPasswordTokenHash = null;
    
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resetPasswordExpiresAt = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $dashboardProjectionsVisible = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $dashboardPlanProgressVisible = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $dashboardTrainingLoadVisible = false;

    #[ORM\Column(options: ['default' => true])]
    private bool $dashboardMonthlyLoadVisible = true;

    #[ORM\Column(options: ['default' => false])]
    private bool $dashboardCoherenceVisible = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $dashboardEfBpmVisible = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $dashboardRaceAvgVisible = false;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['user:read', 'user:write'])]
    private bool $emailHebdo = true;

    #[ORM\Column(length: 128, nullable: true, unique: true)]
    private ?string $googleId = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $meteoCity = null;


    #[ORM\OneToOne(targetEntity: HealthPause::class, mappedBy: 'user', cascade: ['remove'])]
    private ?HealthPause $healthPause = null;

    #[ORM\OneToMany(targetEntity: RunLog::class, mappedBy: 'user', cascade: ['remove'])]
    private Collection $runLogs;

    #[ORM\OneToMany(targetEntity: Race::class, mappedBy: 'user', cascade: ['remove'])]
    private Collection $races;

    #[ORM\OneToMany(targetEntity: \App\Entity\PlanProgress::class, mappedBy: 'user', cascade: ['remove'])]
    private Collection $planProgresses;

    public function __construct()
    {
        $this->createdAt  = new \DateTimeImmutable();
        $this->runLogs    = new ArrayCollection();
        $this->races      = new ArrayCollection();
        $this->planProgresses = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getUsername(): string { return $this->getUserIdentifier(); }
    public function setUsername(string $u): static { $this->username = $u; return $this; }
    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $email): static { $this->email = $email !== null ? strtolower(trim($email)) : null; return $this; }
    public function getUserIdentifier(): string { return $this->username; }
    public function getPassword(): string { return $this->password; }
    public function setPassword(string $p): static { $this->password = $p; return $this; }
    public function getPlainPassword(): ?string { return $this->plainPassword; }
    public function setPlainPassword(?string $p): static { $this->plainPassword = $p; return $this; }
    public function getRoles(): array { return array_unique([...$this->roles, 'ROLE_USER']); }
    public function setRoles(array $r): static { $this->roles = $r; return $this; }
    public function eraseCredentials(): void { $this->plainPassword = null; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    
    public function getResetPasswordTokenHash(): ?string { return $this->resetPasswordTokenHash; }
    public function setResetPasswordTokenHash(?string $hash): static { $this->resetPasswordTokenHash = $hash; return $this; }
    public function getResetPasswordExpiresAt(): ?\DateTimeImmutable { return $this->resetPasswordExpiresAt; }
    public function setResetPasswordExpiresAt(?\DateTimeImmutable $expiresAt): static { $this->resetPasswordExpiresAt = $expiresAt; return $this; }

    public function isDashboardProjectionsVisible(): bool { return $this->dashboardProjectionsVisible; }
    public function setDashboardProjectionsVisible(bool $visible): static { $this->dashboardProjectionsVisible = $visible; return $this; }

    public function isDashboardPlanProgressVisible(): bool { return $this->dashboardPlanProgressVisible; }
    public function setDashboardPlanProgressVisible(bool $visible): static { $this->dashboardPlanProgressVisible = $visible; return $this; }

    public function isDashboardTrainingLoadVisible(): bool { return $this->dashboardTrainingLoadVisible; }
    public function setDashboardTrainingLoadVisible(bool $visible): static { $this->dashboardTrainingLoadVisible = $visible; return $this; }

    public function isDashboardMonthlyLoadVisible(): bool { return $this->dashboardMonthlyLoadVisible; }
    public function setDashboardMonthlyLoadVisible(bool $visible): static { $this->dashboardMonthlyLoadVisible = $visible; return $this; }

    public function isDashboardCoherenceVisible(): bool { return $this->dashboardCoherenceVisible; }
    public function setDashboardCoherenceVisible(bool $visible): static { $this->dashboardCoherenceVisible = $visible; return $this; }

    public function isDashboardEfBpmVisible(): bool { return $this->dashboardEfBpmVisible; }
    public function setDashboardEfBpmVisible(bool $visible): static { $this->dashboardEfBpmVisible = $visible; return $this; }

    public function isDashboardRaceAvgVisible(): bool { return $this->dashboardRaceAvgVisible; }
    public function setDashboardRaceAvgVisible(bool $visible): static { $this->dashboardRaceAvgVisible = $visible; return $this; }

    public function isEmailHebdo(): bool { return $this->emailHebdo; }
    public function setEmailHebdo(bool $emailHebdo): static { $this->emailHebdo = $emailHebdo; return $this; }

    public function getGoogleId(): ?string { return $this->googleId; }
    public function setGoogleId(?string $googleId): static { $this->googleId = $googleId; return $this; }

    public function getMeteoCity(): ?string { return $this->meteoCity; }
    public function setMeteoCity(?string $city): static { $this->meteoCity = $city !== null ? trim($city) : null; return $this; }

    /** @return array<string, bool> */
    public function getDashboardWidgetVisibilityMap(): array
    {
        return [
            DashboardWidgetKeys::PROJECTIONS => $this->dashboardProjectionsVisible,
            DashboardWidgetKeys::PLAN_PROGRESS => $this->dashboardPlanProgressVisible,
            DashboardWidgetKeys::TRAINING_LOAD => $this->dashboardTrainingLoadVisible,
            DashboardWidgetKeys::MONTHLY_LOAD => $this->dashboardMonthlyLoadVisible,
            DashboardWidgetKeys::COHERENCE => $this->dashboardCoherenceVisible,
            DashboardWidgetKeys::EF_BPM => $this->dashboardEfBpmVisible,
            DashboardWidgetKeys::RACE_AVG => $this->dashboardRaceAvgVisible,
        ];
    }

    public function getHealthPause(): ?HealthPause { return $this->healthPause; }
    public function getRunLogs(): Collection { return $this->runLogs; }

    public function isOnHealthPause(): bool
    {
        return $this->healthPause !== null && $this->healthPause->isActive();
    }

    public function isInRecoveryWindow(): bool
    {
        return $this->healthPause !== null && $this->healthPause->isInRecoveryWindow();
    }

    public function setDashboardWidgetVisible(string $widgetKey, bool $visible): static
    {
        switch ($widgetKey) {
            case DashboardWidgetKeys::PROJECTIONS:
                $this->dashboardProjectionsVisible = $visible;
                break;
            case DashboardWidgetKeys::PLAN_PROGRESS:
                $this->dashboardPlanProgressVisible = $visible;
                break;
            case DashboardWidgetKeys::TRAINING_LOAD:
                $this->dashboardTrainingLoadVisible = $visible;
                break;
            case DashboardWidgetKeys::MONTHLY_LOAD:
                $this->dashboardMonthlyLoadVisible = $visible;
                break;
            case DashboardWidgetKeys::COHERENCE:
                $this->dashboardCoherenceVisible = $visible;
                break;
            case DashboardWidgetKeys::RACE_AVG:
                $this->dashboardRaceAvgVisible = $visible;
                break;
            case DashboardWidgetKeys::EF_BPM:
                $this->dashboardEfBpmVisible = $visible;
                break;
            default:
                break;
        }

        return $this;
    }
}
