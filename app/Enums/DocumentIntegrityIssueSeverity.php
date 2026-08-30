<?php

namespace App\Enums;

enum DocumentIntegrityIssueSeverity: string
{
    case Critical = 'critical';
    case High = 'high';
    case Warning = 'warning';

    public function label(): string
    {
        return match ($this) {
            self::Critical => 'Critical',
            self::High => 'High',
            self::Warning => 'Warning',
        };
    }
}
