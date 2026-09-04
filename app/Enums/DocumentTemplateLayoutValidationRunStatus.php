<?php

namespace App\Enums;

enum DocumentTemplateLayoutValidationRunStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Unavailable = 'unavailable';
    case Stale = 'stale';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Valid,
            self::Invalid,
            self::Unavailable,
            self::Stale,
        ], true);
    }

    public function isActive(): bool
    {
        return $this === self::Queued || $this === self::Processing;
    }
}
