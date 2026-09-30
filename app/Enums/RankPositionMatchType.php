<?php

namespace App\Enums;

enum RankPositionMatchType: string
{
    case Exact = 'exact';
    case Created = 'created';
    case Manual = 'manual';
}
