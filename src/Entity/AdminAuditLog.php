<?php

namespace App\Entity;

use App\Repository\AdminAuditLogRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AdminAuditLogRepository::class)]
#[ORM\Table(name: 'admin_audit_log')]
class AdminAuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(nullable: true)]
    private ?int $adminUserId = null;

    #[ORM\Column(length: 64)]
    private string $adminIdentifier = '';

    #[ORM\Column(nullable: true)]
    private ?int $targetUserId = null;

    #[ORM\Column(length: 64)]
    private string $action = '';

    #[ORM\Column(type: 'json')]
    private array $details = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAdminUserId(): ?int
    {
        return $this->adminUserId;
    }

    public function setAdminUserId(?int $adminUserId): static
    {
        $this->adminUserId = $adminUserId;

        return $this;
    }

    public function getAdminIdentifier(): string
    {
        return $this->adminIdentifier;
    }

    public function setAdminIdentifier(string $adminIdentifier): static
    {
        $this->adminIdentifier = $adminIdentifier;

        return $this;
    }

    public function getTargetUserId(): ?int
    {
        return $this->targetUserId;
    }

    public function setTargetUserId(?int $targetUserId): static
    {
        $this->targetUserId = $targetUserId;

        return $this;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function setAction(string $action): static
    {
        $this->action = $action;

        return $this;
    }

    public function getDetails(): array
    {
        return $this->details;
    }

    public function setDetails(array $details): static
    {
        $this->details = $details;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

