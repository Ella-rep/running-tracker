<?php

namespace App\Entity;

const RACE_OWNER_SECURITY = 'object.getUser() == user';

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
    normalizationContext: ['groups' => ['race:read']],
    denormalizationContext: ['groups' => ['race:write']],
    security: 'is_granted("ROLE_USER")',
    operations: [
        new GetCollection(),
        new Post(),
        new Get(security: RACE_OWNER_SECURITY),
        new Put(security: RACE_OWNER_SECURITY),
        new Delete(security: RACE_OWNER_SECURITY),
    ]
)]
#[ApiFilter(OrderFilter::class, properties: ['date' => 'ASC'])]
/**
 * Stores a planned or completed race for a user.
 */
class Race
{
    private const STATUS_DONE_CLASS = 'badge-done';
    private const STATUS_NEXT_CLASS = 'badge-next';
    private const STATUS_FUTURE_CLASS = 'badge-future';
    private const STATUS_DNS_CLASS = 'badge-dns';
    private const STATUS_DNF_CLASS = 'badge-dnf';

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

    /** Values: null (normal), 'dns' (Did Not Start), 'dnf' (Did Not Finish) */
    #[ORM\Column(length: 3, nullable: true)]
    #[Groups(['race:read', 'race:write'])]
    private ?string $dnfStatus = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['race:read', 'race:write'])]
    private ?string $dnfComment = null;

    #[ORM\Column]
    #[Groups(['race:read'])]
    private \DateTimeImmutable $createdAt;

    /**
     * Initializes immutable creation timestamp.
     */
    public function __construct() { $this->createdAt = new \DateTimeImmutable(); }

    /**
     * Returns race identifier.
     */
    public function getId(): ?int { return $this->id; }

    /**
     * Returns owner user.
     */
    public function getUser(): User { return $this->user; }

    /**
     * Sets owner user.
     */
    public function setUser(User $u): static { $this->user = $u; return $this; }

    /**
     * Returns race name.
     */
    public function getName(): string { return $this->name; }

    /**
     * Sets race name.
     */
    public function setName(string $n): static { $this->name = $n; return $this; }

    /**
     * Returns race date (YYYY-MM-DD).
     */
    public function getDate(): string { return $this->date; }

    /**
     * Sets race date (YYYY-MM-DD).
     */
    public function setDate(string $d): static { $this->date = $d; return $this; }

    /**
     * Returns race distance label.
     */
    public function getDistance(): ?string { return $this->distance; }

    /**
     * Sets race distance label.
     */
    public function setDistance(?string $d): static { $this->distance = $d; return $this; }

    /**
     * Returns target objective time.
     */
    public function getObjective(): ?string { return $this->objective; }

    /**
     * Sets target objective time.
     */
    public function setObjective(?string $o): static { $this->objective = $o; return $this; }

    /**
     * Returns achieved result time.
     */
    public function getResult(): ?string { return $this->result; }

    /**
     * Sets achieved result time.
     */
    public function setResult(?string $r): static { $this->result = $r; return $this; }

    /**
     * Returns DNS/DNF status (null = normal, 'dns', 'dnf').
     */
    public function getDnfStatus(): ?string { return $this->dnfStatus; }

    /**
     * Sets DNS/DNF status.
     */
    public function setDnfStatus(?string $s): static
    {
        $this->dnfStatus = in_array($s, ['dns', 'dnf'], true) ? $s : null;
        return $this;
    }

    /**
     * Returns optional DNS/DNF comment.
     */
    public function getDnfComment(): ?string { return $this->dnfComment; }

    /**
     * Sets optional DNS/DNF comment.
     */
    public function setDnfComment(?string $c): static { $this->dnfComment = $c; return $this; }

    /**
     * Returns entity creation timestamp.
     */
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /**
     * Returns race status CSS class for UI badges.
     */
    #[Groups(['race:read'])]
    public function getStatusClass(): string
    {
        if ($this->dnfStatus === 'dns') {
            return self::STATUS_DNS_CLASS;
        }

        if ($this->dnfStatus === 'dnf') {
            return self::STATUS_DNF_CLASS;
        }

        if ($this->hasResult()) {
            return self::STATUS_DONE_CLASS;
        }

        $daysTo = $this->daysToRace();
        if ($daysTo !== null && $daysTo <= 14 && $daysTo >= 0) {
            return self::STATUS_NEXT_CLASS;
        }

        return self::STATUS_FUTURE_CLASS;
    }

    /**
     * Returns race status label for UI badges.
     */
    #[Groups(['race:read'])]
    public function getStatusLabel(): string
    {
        if ($this->dnfStatus === 'dns') {
            return 'DNS';
        }

        if ($this->dnfStatus === 'dnf') {
            return 'DNF';
        }

        $label = 'A venir';

        if ($this->hasResult()) {
            $label = '✓ Terminee';
        } else {
            $daysTo = $this->daysToRace();
            if ($daysTo !== null) {
                if ($daysTo < 0) {
                    $label = 'Passee';
                } elseif ($daysTo <= 10) {
                    $label = sprintf('J-%d', $daysTo);
                } else {
                    $label = sprintf('S-%d', (int) round($daysTo / 7));
                }
            }
        }

        return $label;
    }

    /**
     * Returns signed time delta against objective, formatted as mm:ss.
     */
    #[Groups(['race:read'])]
    public function getResultDelta(): string
    {
        $resultSec = $this->durationToSeconds($this->result);
        $objectiveSec = $this->durationToSeconds($this->objective);

        if ($resultSec === null || $objectiveSec === null) {
            return '—';
        }

        $delta = $resultSec - $objectiveSec;
        $prefix = $delta < 0 ? '-' : '+';
        $abs = abs($delta);
        $minutes = intdiv($abs, 60);
        $seconds = $abs % 60;

        return sprintf('%s%02d:%02d', $prefix, $minutes, $seconds);
    }

    private function hasResult(): bool
    {
        return trim((string) $this->result) !== ''
            || $this->dnfStatus === 'dns'
            || $this->dnfStatus === 'dnf';
    }

    private function daysToRace(): ?int
    {
        $raceDate = \DateTimeImmutable::createFromFormat('Y-m-d', trim((string) $this->date));
        if (!$raceDate instanceof \DateTimeImmutable) {
            return null;
        }

        $today = new \DateTimeImmutable('today');
        return (int) $today->diff($raceDate)->format('%r%a');
    }

    private function durationToSeconds(?string $duration): ?int
    {
        $value = trim((string) $duration);
        $seconds = null;

        if ($value === '') {
            return $seconds;
        }

        $parts = array_map('intval', explode(':', $value));
        if (count($parts) === 3) {
            $seconds = ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2];
        } elseif (count($parts) === 2) {
            $seconds = ($parts[0] * 60) + $parts[1];
        }

        return $seconds;
    }
}
