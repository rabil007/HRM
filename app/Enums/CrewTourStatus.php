<?php

namespace App\Enums;

enum CrewTourStatus: string
{
    case Normal = 'normal';
    case DueWithin30Days = 'due_within_30_days';
    case DueWithin14Days = 'due_within_14_days';
    case DueWithin7Days = 'due_within_7_days';
    case DueToday = 'due_today';
    case Overdue = 'overdue';
    case MissingTourRule = 'missing_tour_rule';
    case MissingSignoff = 'missing_signoff';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::DueWithin30Days => 'Due within 30 days',
            self::DueWithin14Days => 'Due within 14 days',
            self::DueWithin7Days => 'Due within 7 days',
            self::DueToday => 'Due today',
            self::Overdue => 'Overdue',
            self::MissingTourRule => 'Missing Tour of Duty',
            self::MissingSignoff => 'Missing Planned Sign-Off',
        };
    }

    public function severity(): string
    {
        return match ($this) {
            self::Normal => 'normal',
            self::DueWithin30Days => 'info',
            self::DueWithin14Days => 'warning',
            self::DueWithin7Days => 'critical',
            self::DueToday => 'critical',
            self::Overdue => 'critical',
            self::MissingTourRule, self::MissingSignoff => 'warning',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Filterable tour status buckets for Current Crew / dashboard.
     *
     * @return list<self>
     */
    public static function filterable(): array
    {
        return [
            self::DueWithin30Days,
            self::DueWithin14Days,
            self::DueWithin7Days,
            self::DueToday,
            self::Overdue,
            self::MissingTourRule,
            self::MissingSignoff,
        ];
    }
}
