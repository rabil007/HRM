<?php

namespace App\Enums;

enum EmailTemplateCategory: string
{
    case Document = 'document';
    case Hr = 'hr';
    case Payroll = 'payroll';
    case Notification = 'notification';
    case General = 'general';

    public function label(): string
    {
        return match ($this) {
            self::Document => 'Documents',
            self::Hr => 'HR',
            self::Payroll => 'Payroll',
            self::Notification => 'Notifications',
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
