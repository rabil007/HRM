<?php

namespace App\Enums;

enum HistoricalCrewImportRowStatus: string
{
    case Ready = 'ready';
    case Warning = 'warning';
    case Blocked = 'blocked';
    case Imported = 'imported';
    case ImportedWithWarnings = 'imported_with_warnings';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Ready => 'Ready',
            self::Warning => 'Warning',
            self::Blocked => 'Blocked',
            self::Imported => 'Imported',
            self::ImportedWithWarnings => 'Imported with warnings',
            self::Failed => 'Failed',
            self::Skipped => 'Skipped',
        };
    }
}
