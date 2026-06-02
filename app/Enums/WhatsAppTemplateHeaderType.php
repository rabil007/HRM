<?php

namespace App\Enums;

enum WhatsAppTemplateHeaderType: string
{
    case Document = 'document';
    case Text = 'text';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Document => 'Document',
            self::Text => 'Text',
            self::None => 'None',
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
