<?php

namespace App\Enums\Recruitment;

enum CandidateInterviewOutcome: string
{
    case Selected = 'selected';
    case NotSelected = 'not_selected';

    public function label(): string
    {
        return match ($this) {
            self::Selected => 'Selected',
            self::NotSelected => 'Not Selected',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Selected => 'success',
            self::NotSelected => 'destructive',
        };
    }
}
