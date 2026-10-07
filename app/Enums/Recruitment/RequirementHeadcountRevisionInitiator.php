<?php

namespace App\Enums\Recruitment;

enum RequirementHeadcountRevisionInitiator: string
{
    case Requester = 'requester';
    case Recruiter = 'recruiter';

    public function label(): string
    {
        return match ($this) {
            self::Requester => 'Requester',
            self::Recruiter => 'Recruiter',
        };
    }
}
