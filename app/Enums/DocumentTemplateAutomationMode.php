<?php

namespace App\Enums;

enum DocumentTemplateAutomationMode: string
{
    case None = 'none';
    case Preset = 'preset';

    public function label(): string
    {
        return match ($this) {
            self::None => 'None',
            self::Preset => 'Preset',
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
