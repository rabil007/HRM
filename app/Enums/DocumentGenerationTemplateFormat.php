<?php

namespace App\Enums;

enum DocumentGenerationTemplateFormat: string
{
    case Content = 'content';
    case PdfOverlay = 'pdf_overlay';

    public function label(): string
    {
        return match ($this) {
            self::Content => 'Content',
            self::PdfOverlay => 'PDF Template',
        };
    }

    public function isContent(): bool
    {
        return $this === self::Content;
    }

    public function isPdfOverlay(): bool
    {
        return $this === self::PdfOverlay;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
