<?php

namespace App\Enums;

enum CrewTourOfDutySource: string
{
    case AssignmentOverride = 'assignment_override';
    case CompanyRankPolicy = 'company_rank_policy';
    case GlobalRankDefault = 'global_rank_default';

    public function label(): string
    {
        return match ($this) {
            self::AssignmentOverride => 'Assignment Override',
            self::CompanyRankPolicy => 'Company Rank Policy',
            self::GlobalRankDefault => 'Global Rank Default',
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
