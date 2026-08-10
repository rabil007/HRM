<?php

namespace App\Enums;

enum CrewOperationalAlertType: string
{
    case SignoffOverdue = 'signoff_overdue';
    case SignoffNoRelief = 'signoff_no_relief';
    case ReliefNotReady = 'relief_not_ready';
    case CurrentManningGap = 'current_manning_gap';
    case ProjectedManningGap = 'projected_manning_gap';

    public function label(): string
    {
        return match ($this) {
            self::SignoffOverdue => 'Sign-off overdue',
            self::SignoffNoRelief => 'Sign-off approaching — no relief',
            self::ReliefNotReady => 'Relief not ready',
            self::CurrentManningGap => 'Current manning gap',
            self::ProjectedManningGap => 'Projected manning gap',
        };
    }

    public function settingsColumn(): string
    {
        return match ($this) {
            self::SignoffOverdue => 'alert_signoff_overdue',
            self::SignoffNoRelief => 'alert_signoff_no_relief',
            self::ReliefNotReady => 'alert_relief_not_ready',
            self::CurrentManningGap => 'alert_current_manning_gap',
            self::ProjectedManningGap => 'alert_projected_manning_gap',
        };
    }
}
