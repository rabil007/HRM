<?php

namespace App\Support\CrewOperations\Reset;

use App\Models\SalaryInput;

/**
 * Marks salary inputs created exclusively by the resettable crew timesheet import workflow.
 */
final class CrewOperationsResetSalaryInputOwnership
{
    public const BREAKDOWN_KEY = 'crew_operations_owned';

    public const NOTES_MARKER = 'crew_operations:timesheet_import';

    public static function isOwned(SalaryInput $input): bool
    {
        return $input->notes === self::NOTES_MARKER;
    }

    /**
     * @return array{notes: string}
     */
    public static function attributesForImportUpsert(): array
    {
        return [
            'notes' => self::NOTES_MARKER,
        ];
    }
}
