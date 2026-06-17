<?php

namespace App\Entity;

final class DashboardWidgetKeys
{
    public const PROJECTIONS = 'projections';
    public const EF_BPM = 'ef_bpm';
    public const TRAINING_LOAD = 'training_load';
    public const PLAN_PROGRESS = 'plan_progress';
    public const MONTHLY_LOAD = 'monthly_load';
    public const COHERENCE = 'coherence';
    public const RACE_AVG = 'race_avg';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::PROJECTIONS,
            self::EF_BPM,
            self::TRAINING_LOAD,
            self::PLAN_PROGRESS,
            self::MONTHLY_LOAD,
            self::COHERENCE,
            self::RACE_AVG,
        ];
    }
}
