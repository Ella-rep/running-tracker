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
     * @return array{title:string,message:string,level:string,endsAt:?string,signature:string}|null
     */
    public function getActiveAdminAnnouncement(): ?array
    {
        $result = $this->cachedAnnouncement;

        if ($result === null) {
            $announcement = null;
            try {
                $announcement = $this->announcementRepository->findCurrent();
            } catch (TableNotFoundException) {
                $announcement = null;
            } catch (\Throwable) {
                // Keep header rendering resilient during partial schema updates.
                $announcement = null;
            }

            if ($announcement !== null) {
                $title = method_exists($announcement, 'getTitle')
                    ? (string) $announcement->getTitle()
                    : 'Annonce';
                $endsAt = $announcement->getEndsAt()?->format('d/m/Y H:i');
                $signature = sha1(
                    implode('|', [
                        $title,
                        $announcement->getLevel(),
                        $announcement->getMessage(),
                        $endsAt ?? '',
                    ])
                );

                $result = [
                    'title' => $title,
                    'message' => $announcement->getMessage(),
                    'level' => $announcement->getLevel(),
                    'endsAt' => $endsAt,
                    'signature' => $signature,
                ];
                $this->cachedAnnouncement = $result;
            }
        }

        return $result;
    }
}

