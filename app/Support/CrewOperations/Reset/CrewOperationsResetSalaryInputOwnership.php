<?php

namespace App\Support\CrewOperations\Reset;

use App\Models\SalaryInput;

/**
 * Marks salary inputs created exclusively by the resettable crew timesheet import workflow.
 */
final class CrewOperationsResetSalaryInputOwnership
{
    public const BREAKDOWN_KEY = 'crew_operations_owned';

    public const COLUMN = 'crew_operations_timesheet_import_owned';

    public static function isOwned(SalaryInput $input): bool
    {
        return (bool) $input->{self::COLUMN};
    }

    /**
     * @return array{crew_operations_timesheet_import_owned: true}
     */
    public static function attributesForImportCreate(): array
    {
        return [
            self::COLUMN => true,
        ];
    }
}
