<?php

namespace App\Enums;

enum CrewProjectedManningStatus: string
{
    case Covered = 'covered';
    case CoveredByIncoming = 'covered_by_incoming';
    case CurrentGap = 'current_gap';
    case FutureGap = 'future_gap';
    case Overlap = 'overlap';

    public function label(): string
    {
        return match ($this) {
            self::Covered => 'Covered',
            self::CoveredByIncoming => 'Covered by Incoming',
            self::CurrentGap => 'Current Gap',
            self::FutureGap => 'Future Gap',
            self::Overlap => 'Overlap',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
