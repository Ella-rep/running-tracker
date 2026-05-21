<?php

namespace App\ApiResource;

final class PlanSessionsReplaceInput
{
    /** @var array<int, array<string, mixed>> */
    public array $sessions = [];

    /** @var array<int|string, bool> */
    public array $doneMap = [];
}

