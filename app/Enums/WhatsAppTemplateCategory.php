<?php

namespace App\Enums;

enum WhatsAppTemplateCategory: string
{
    case Document = 'document';
    case Payroll = 'payroll';
    case General = 'general';

    public function label(): string
    {
        return match ($this) {
            self::Document => 'Document',
            self::Payroll => 'Payroll',
            self::General => 'General',
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
