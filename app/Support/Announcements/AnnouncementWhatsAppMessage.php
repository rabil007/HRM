<?php

namespace App\Support\Announcements;

use App\Models\Announcement;
use Illuminate\Support\Str;

final class AnnouncementWhatsAppMessage
{
    public const MAX_LENGTH = 500;

    public const EMPTY_VIEW_LINK = 'N/A';

    public static function for(Announcement $announcement): string
    {
        $message = filled($announcement->whatsapp_message)
            ? (string) $announcement->whatsapp_message
            : self::fromHtml((string) $announcement->body_html);

        $message = self::normalize($message);

        if ($message === '') {
            $message = self::normalize((string) $announcement->title);
        }

        return Str::limit($message, self::MAX_LENGTH, '');
    }

    public static function viewLink(Announcement $announcement): string
    {
        $link = trim((string) ($announcement->whatsapp_link ?? ''));

        return $link !== '' ? $link : self::EMPTY_VIEW_LINK;
    }

    public static function fromHtml(string $html): string
    {
        $withLineBreaks = preg_replace(
            '/<(?:br\s*\/?|\/(?:p|div|li|h[1-6]|blockquote))\s*>/i',
            "\n",
            $html,
        ) ?? $html;

        return self::normalize(html_entity_decode(
            strip_tags($withLineBreaks),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        ));
    }

    public static function normalize(string $message): string
    {
        // Meta rejects template body params with newlines, tabs, or 4+ consecutive spaces.
        $message = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $message);
        $message = preg_replace('/\s+/u', ' ', $message) ?? $message;

        return trim($message);
    }

    public static function templateParameter(string $value): string
    {
        return self::normalize($value);
    }
}
