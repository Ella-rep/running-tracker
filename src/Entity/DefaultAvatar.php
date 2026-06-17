<?php

namespace App\Entity;

use App\Repository\DefaultAvatarRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Built-in avatar offered to users who don't upload their own picture.
 * Binary stored in DB (BYTEA).
 */
#[ORM\Entity(repositoryClass: DefaultAvatarRepository::class)]
#[ORM\Table(name: 'default_avatar')]
class DefaultAvatar
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $slug = '';

    #[ORM\Column(length: 100)]
    private string $label = '';

    /** @var resource|string Raw binary of the avatar image (BYTEA). */
    #[ORM\Column(type: 'blob')]
    private $imageData;

    #[ORM\Column(length: 100)]
    private string $mimeType = 'image/webp';

    #[ORM\Column(options: ['default' => 0])]
    private int $sortOrder = 0;

    public function getId(): ?int { return $this->id; }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): static { $this->slug = $slug; return $this; }
    public function getLabel(): string { return $this->label; }
    public function setLabel(string $label): static { $this->label = $label; return $this; }
    /** @return resource|string */
    public function getImageData() { return $this->imageData; }
    /** @param resource|string $data */
    public function setImageData($data): static { $this->imageData = $data; return $this; }
    public function getMimeType(): string { return $this->mimeType; }
    public function setMimeType(string $mime): static { $this->mimeType = $mime; return $this; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $order): static { $this->sortOrder = $order; return $this; }

    /** Binary content as string (reads stream if needed). */
    public function getImageBinary(): string
    {
        if (is_resource($this->imageData)) { rewind($this->imageData); return (string) stream_get_contents($this->imageData); }
        return (string) $this->imageData;
    }
}
