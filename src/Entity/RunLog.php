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

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['log:read', 'log:write'])]
    private ?string $notes = null;

    #[ORM\ManyToOne(targetEntity: PlanDetails::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['log:read', 'log:write'])]
    private ?PlanDetails $plannedSession = null;

    #[ORM\Column]
    #[Groups(['log:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct() { $this->createdAt = new \DateTimeImmutable(); }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function setUser(User $u): static { $this->user = $u; return $this; }
    public function getDate(): string { return $this->date; }
    public function setDate(string $d): static { $this->date = $d; return $this; }
    public function getKm(): ?float { return $this->km; }
    public function setKm(?float $k): static { $this->km = $k; return $this; }
    public function getDuration(): ?string { return $this->duration; }
    public function setDuration(?string $d): static { $this->duration = $d; return $this; }
    public function getAllure(): ?string { return $this->allure; }
    public function setAllure(?string $a): static { $this->allure = $a; return $this; }
    public function getGap(): ?string { return $this->gap; }
    public function setGap(?string $g): static { $this->gap = $g; return $this; }
    public function getDplus(): ?int { return $this->dplus; }
    public function setDplus(?int $d): static { $this->dplus = $d; return $this; }
    public function getBpm(): ?int { return $this->bpm; }
    public function setBpm(?int $b): static { $this->bpm = $b; return $this; }
    public function getRunType(): ?string { return $this->runType; }
    public function setRunType(?string $t): static { $this->runType = $t; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $n): static { $this->notes = $n; return $this; }
    public function getPlannedSession(): ?PlanDetails { return $this->plannedSession; }
    public function setPlannedSession(?PlanDetails $plannedSession): static { $this->plannedSession = $plannedSession; return $this; }
    #[Groups(['log:read'])]
    public function getPlannedSessionId(): ?int { return $this->plannedSession?->getId(); }
    #[Groups(['log:read'])]
    public function getPlannedSessionLabel(): ?string
    {
        if (!$this->plannedSession) {
            return null;
        }

        $date = $this->plannedSession->getSessionDate()?->format('Y-m-d');
        $planName = $this->plannedSession->getPlanName();
        $position = $this->plannedSession->getPosition();
        $format = trim((string) $this->plannedSession->getFormat());

        return trim(sprintf(
            '%s · %s · Seance %d%s',
            $date ?: 'Sans date',
            $planName,
            $position,
            $format !== '' ? ' · ' . $format : ''
        ));
    }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
