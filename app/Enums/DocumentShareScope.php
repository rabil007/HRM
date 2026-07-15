<?php

namespace App\Enums;

enum DocumentShareScope: string
{
    case Folder = 'folder';
    case Files = 'files';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
