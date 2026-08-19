<?php

namespace App\Domain\Analytics;

enum RollupDimension: string
{
    case NOTE = 'note';
    case ACTOR = 'actor';
    case EVENT = 'event';
}
