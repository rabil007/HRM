<?php

namespace App\Enums\Recruitment;

enum CandidateSource: string
{
    case Website = 'website';
    case LinkedIn = 'linkedin';
    case Referral = 'referral';
    case Agency = 'agency';
    case WalkIn = 'walk_in';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Website => 'Website',
            self::LinkedIn => 'LinkedIn',
            self::Referral => 'Referral',
            self::Agency => 'Agency',
            self::WalkIn => 'Walk-in',
            self::Other => 'Other',
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
