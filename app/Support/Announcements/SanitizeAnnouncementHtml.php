<?php

namespace App\Support\Announcements;

final class SanitizeAnnouncementHtml
{
    private const ALLOWED_TAGS = '<p><br><strong><b><em><i><u><ul><ol><li><a><h2><h3><h4><blockquote>';

    public static function handle(string $html): string
    {
        $cleaned = strip_tags($html, self::ALLOWED_TAGS);

        return preg_replace('/\s+on\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $cleaned) ?? $cleaned;
    }
}
