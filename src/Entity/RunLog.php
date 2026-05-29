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
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use App\Entity\PlanDetails;
use App\Repository\RunLogRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: RunLogRepository::class)]
#[ORM\Table(name: 'run_logs')]
#[ApiResource(
    normalizationContext: ['groups' => ['log:read']],
    denormalizationContext: ['groups' => ['log:write']],
    security: 'is_granted("ROLE_USER")',
    operations: [
        new GetCollection(),
        new Post(processor: 'App\\State\\RunLogProcessor'),
        new Get(security: self::OWNER_SECURITY),
        new Put(security: self::OWNER_SECURITY, processor: 'App\\State\\RunLogProcessor'),
        new Delete(security: self::OWNER_SECURITY),
    ]
)]
#[ApiFilter(OrderFilter::class, properties: ['date' => 'DESC'])]
#[ApiFilter(SearchFilter::class, properties: ['runType' => 'exact'])]
/**
 * Stores a user running activity log entry.
 */
class RunLog
{
    private const OWNER_SECURITY = 'object.getUser() == user';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    #[Groups(['log:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'runLogs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 10)]
    #[Assert\NotBlank]
    #[Assert\Date]
    #[Groups(['log:read', 'log:write'])]
    private string $date = '';

    #[ORM\Column(nullable: true)]
    #[Groups(['log:read', 'log:write'])]
    private ?float $km = null;

    #[ORM\Column(length: 12, nullable: true)]
    #[Groups(['log:read', 'log:write'])]
    private ?string $duration = null;

    #[ORM\Column(length: 8, nullable: true)]
    #[Groups(['log:read', 'log:write'])]
    private ?string $allure = null;

    #[ORM\Column(length: 8, nullable: true)]
    #[Groups(['log:read', 'log:write'])]
    private ?string $gap = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['log:read', 'log:write'])]
    private ?int $dplus = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['log:read', 'log:write'])]
    private ?int $bpm = null;

    #[ORM\Column(length: 16, nullable: true)]
    #[Groups(['log:read', 'log:write'])]
    private ?string $runType = null;

    #[ORM\Column(length: 128, nullable: true)]
    #[Groups(['log:read', 'log:write'])]
    private ?string $courseName = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['log:read', 'log:write'])]
    private ?string $notes = null;

    #[ORM\Column(length: 16, nullable: true)]
    #[Groups(['log:read', 'log:write'])]
    private ?string $perceivedEffort = null;

    #[ORM\ManyToOne(targetEntity: PlanDetails::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['log:read', 'log:write'])]
    private ?PlanDetails $plannedSession = null;

    #[ORM\Column]
    #[Groups(['log:read'])]
    private \DateTimeImmutable $createdAt;

    /**
     * Initializes immutable creation timestamp.
     */
    public function __construct() { $this->createdAt = new \DateTimeImmutable(); }

    /**
     * Returns the log identifier.
     */
    public function getId(): ?int { return $this->id; }

    /**
     * Returns the owner user.
     */
    public function getUser(): User { return $this->user; }

    /**
     * Sets the owner user.
     */
    public function setUser(User $u): static { $this->user = $u; return $this; }

    /**
     * Returns the run date (YYYY-MM-DD).
     */
    public function getDate(): string { return $this->date; }

    /**
     * Sets the run date (YYYY-MM-DD).
     */
    public function setDate(string $d): static { $this->date = $d; return $this; }

    /**
     * Returns the distance in kilometers.
     */
    public function getKm(): ?float { return $this->km; }

    /**
     * Sets the distance in kilometers.
     */
    public function setKm(?float $k): static { $this->km = $k; return $this; }

    /**
     * Returns the duration (HH:MM:SS).
     */
    public function getDuration(): ?string { return $this->duration; }

    /**
     * Sets the duration (HH:MM:SS).
     */
    public function setDuration(?string $d): static { $this->duration = $d; return $this; }

    /**
     * Returns the average pace string.
     */
    public function getAllure(): ?string { return $this->allure; }

    /**
     * Sets the average pace string.
     */
    public function setAllure(?string $a): static { $this->allure = $a; return $this; }

    /**
     * Returns the grade-adjusted pace.
     */
    public function getGap(): ?string { return $this->gap; }

    /**
     * Sets the grade-adjusted pace.
     */
    public function setGap(?string $g): static { $this->gap = $g; return $this; }

    /**
     * Returns elevation gain in meters.
     */
    public function getDplus(): ?int { return $this->dplus; }

    /**
     * Sets elevation gain in meters.
     */
    public function setDplus(?int $d): static { $this->dplus = $d; return $this; }

    /**
     * Returns average heart rate.
     */
    public function getBpm(): ?int { return $this->bpm; }

    /**
     * Sets average heart rate.
     */
    public function setBpm(?int $b): static { $this->bpm = $b; return $this; }

    /**
     * Returns run type identifier.
     */
    public function getRunType(): ?string { return $this->runType; }

    /**
     * Sets run type identifier.
     */
    public function setRunType(?string $t): static { $this->runType = $t; return $this; }

    /**
     * Returns linked race name (manual or selected upcoming race).
     */
    public function getCourseName(): ?string { return $this->courseName; }

    /**
     * Sets linked race name.
     */
    public function setCourseName(?string $courseName): static { $this->courseName = $courseName; return $this; }

    /**
     * Returns free-form notes.
     */
    public function getNotes(): ?string { return $this->notes; }

    /**
     * Sets free-form notes.
     */
    public function setNotes(?string $n): static { $this->notes = $n; return $this; }

    /**
     * Returns perceived effort value.
     */
    public function getPerceivedEffort(): ?string { return $this->perceivedEffort; }

    /**
     * Sets perceived effort value.
     */
    public function setPerceivedEffort(?string $perceivedEffort): static { $this->perceivedEffort = $perceivedEffort; return $this; }

    /**
     * Returns the linked planned session.
     */
    public function getPlannedSession(): ?PlanDetails { return $this->plannedSession; }

    /**
     * Sets the linked planned session.
     */
    public function setPlannedSession(?PlanDetails $plannedSession): static { $this->plannedSession = $plannedSession; return $this; }

    /**
     * Returns linked planned session identifier.
     */
    #[Groups(['log:read'])]
    public function getPlannedSessionId(): ?int { return $this->plannedSession?->getId(); }

    /**
     * Returns a human-readable label for linked planned session.
     */
    #[Groups(['log:read'])]
    public function getPlannedSessionLabel(): ?string
    {
        if (!$this->plannedSession) {
            return null;
        }

        $position = $this->plannedSession->getPosition();
        $format = trim((string) $this->plannedSession->getFormat());

        if ($format !== '') {
            return sprintf('Séance %d · %s', $position, $format);
        }

        return sprintf('Séance %d', $position);
    }

    /**
     * Returns entity creation timestamp.
     */
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
