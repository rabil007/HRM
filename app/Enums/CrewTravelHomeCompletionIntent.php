<?php

namespace App\Enums;

enum CrewTravelHomeCompletionIntent: string
{
    case Close = 'close';
    case Redeploy = 'redeploy';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_values(array_column(self::cases(), 'value'));
    }
}
