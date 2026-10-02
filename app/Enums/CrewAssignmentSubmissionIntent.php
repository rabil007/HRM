<?php

namespace App\Enums;

enum CrewAssignmentSubmissionIntent: string
{
    case Start = 'start';
    /** @deprecated Phase 3 — new Planned CrewAssignments must be created in Crew Planning. Kept for request detection / clear rejection. */
    case Plan = 'plan';
    case Draft = 'draft';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_values(array_column(self::cases(), 'value'));
    }

    /**
     * @return list<string>
     */
    public static function createValues(): array
    {
        return [
            self::Start->value,
            self::Draft->value,
        ];
    }

    public static function legacyPlanBlockedMessage(): string
    {
        return 'New Planned crew assignments cannot be created from Crew Assignment. Use Crew Planning for vacant or named future plans, then Start Mobilisation when ready.';
    }
}
