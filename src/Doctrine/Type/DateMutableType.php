<?php

namespace App\Doctrine\Type;

use Doctrine\DBAL\Types\DateType;

/**
 * Compatibility alias for legacy metadata that still references "date_mutable".
 */
class DateMutableType extends DateType
{
    public const NAME = 'date_mutable';

    public function getName(): string
    {
        return self::NAME;
    }
}
