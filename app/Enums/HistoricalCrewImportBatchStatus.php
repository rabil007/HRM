<?php

namespace App\Enums;

enum HistoricalCrewImportBatchStatus: string
{
    case Importing = 'importing';
    case Completed = 'completed';
    case CompletedWithErrors = 'completed_with_errors';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Importing => 'Importing',
            self::Completed => 'Completed',
            self::CompletedWithErrors => 'Completed with warnings',
            self::Failed => 'Failed',
        };
    }
}
