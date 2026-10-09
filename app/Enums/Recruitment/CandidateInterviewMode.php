<?php

namespace App\Enums\Recruitment;

enum CandidateInterviewMode: string
{
    case Phone = 'phone';
    case Video = 'video';
    case InPerson = 'in_person';

    public function label(): string
    {
        return match ($this) {
            self::Phone => 'Phone',
            self::Video => 'Video',
            self::InPerson => 'In Person',
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
