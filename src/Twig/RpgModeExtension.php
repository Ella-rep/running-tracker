<?php

namespace App\Twig;

use App\Entity\User;
use App\Service\GamificationWidgetService;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Injects rpg_mode and rpg_data globally into every Twig template.
 * rpg_mode = true when the user has activated RPG mode in their profile.
 * rpg_data = widget data (null when mode is off or user not logged in).
 */
final class RpgModeExtension extends AbstractExtension implements GlobalsInterface
{
    private bool $resolved = false;
    private bool $rpgMode = false;
    private ?array $rpgData = null;

    public function __construct(
        private readonly Security $security,
        private readonly GamificationWidgetService $gamification,
    ) {}

    public function getGlobals(): array
    {
        if (!$this->resolved) {
            $this->resolved = true;
            $user = $this->security->getUser();
            if ($user instanceof User && $user->isRpgMode()) {
                $this->rpgMode = true;
                $this->rpgData = $this->gamification->buildWidgetData($user);
            }
        }

        return [
            'rpg_mode' => $this->rpgMode,
            'rpg_data' => $this->rpgData,
        ];
    }
}
