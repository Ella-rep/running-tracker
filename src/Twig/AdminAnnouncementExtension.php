<?php

namespace App\Twig;

use App\Repository\AdminAnnouncementRepository;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class AdminAnnouncementExtension extends AbstractExtension
{
    private ?array $cachedAnnouncement = null;

    public function __construct(private readonly AdminAnnouncementRepository $announcementRepository)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('active_admin_announcement', [$this, 'getActiveAdminAnnouncement']),
        ];
    }

    /**
     * @return array{message:string,level:string,endsAt:?string}|null
     */
    public function getActiveAdminAnnouncement(): ?array
    {
        if ($this->cachedAnnouncement !== null) {
            return $this->cachedAnnouncement;
        }

        try {
            $announcement = $this->announcementRepository->findCurrent();
        } catch (TableNotFoundException) {
            return null;
        } catch (\Throwable) {
            // Keep header rendering resilient during partial schema updates.
            return null;
        }

        if ($announcement === null) {
            return null;
        }

        $this->cachedAnnouncement = [
            'message' => $announcement->getMessage(),
            'level' => $announcement->getLevel(),
            'endsAt' => $announcement->getEndsAt()?->format('d/m/Y H:i'),
        ];

        return $this->cachedAnnouncement;
    }
}

