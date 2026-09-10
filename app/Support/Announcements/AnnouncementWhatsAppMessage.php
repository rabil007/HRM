<?php

namespace App\Support\Announcements;

use App\Models\Announcement;
use InvalidArgumentException;

final class AnnouncementWhatsAppMessage
{
    public const MAX_LENGTH = 500;

    /**
     * Meta dynamic text-header parameter limit for Announcement Title + Message templates.
     */
    public const META_TEXT_HEADER_MAX_LENGTH = 60;

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

        return self::limitMessage($message, self::MAX_LENGTH);
    }

    /**
     * Title + Body (v2) body parameter: custom/canonical message with optional link appended.
     * Never emits N/A for a blank link. Never partially truncates the URL.
     *
     * @throws InvalidArgumentException when the URL cannot fit within MAX_LENGTH
     */
    public static function resolvedBodyWithOptionalLink(Announcement $announcement): string
    {
        return self::composeResolvedBody(
            self::for($announcement),
            self::optionalLink($announcement),
        );
    }

    /**
     * Compose the WhatsApp body parameter while preserving a complete optional URL.
     *
     * @throws InvalidArgumentException when the URL cannot fit within MAX_LENGTH
     */
    public static function composeResolvedBody(string $message, ?string $link): string
    {
        $message = self::normalize($message);
        $link = $link !== null ? trim($link) : '';

        if ($link === '') {
            return self::limitMessage($message, self::MAX_LENGTH);
        }

        if (! self::optionalLinkFits($link)) {
            throw new InvalidArgumentException(
                'The WhatsApp link is too long to fit in the WhatsApp message body.',
            );
        }

        if ($message === '') {
            return $link;
        }

        $separator = ' ';
        $maxMessageLength = self::MAX_LENGTH - mb_strlen($separator.$link);
        $message = self::limitMessage($message, $maxMessageLength);

        if ($message === '') {
            return $link;
        }

        return $message.$separator.$link;
    }

    /**
     * Whether an optional WhatsApp URL can be preserved in full within MAX_LENGTH.
     */
    public static function optionalLinkFits(?string $link): bool
    {
        $link = $link !== null ? trim($link) : '';

        if ($link === '') {
            return true;
        }

        return mb_strlen($link) <= self::MAX_LENGTH;
    }

    /**
     * Normalize and constrain a title for Meta's dynamic text-header parameter.
     * Does not change the canonical Announcement title stored in OMS-HRM.
     */
    public static function metaTextHeader(string $title): string
    {
        return self::limitMessage(
            self::normalize($title),
            self::META_TEXT_HEADER_MAX_LENGTH,
        );
    }

    public static function viewLink(Announcement $announcement): string
    {
        $link = trim((string) ($announcement->whatsapp_link ?? ''));

        return $link !== '' ? $link : self::EMPTY_VIEW_LINK;
    }

    public static function optionalLink(Announcement $announcement): ?string
    {
        $link = trim((string) ($announcement->whatsapp_link ?? ''));

        return $link !== '' ? $link : null;
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

    private static function limitMessage(string $message, int $maxLength): string
    {
        if ($maxLength <= 0) {
            return '';
        }

        if (mb_strlen($message) <= $maxLength) {
            return $message;
        }

        return rtrim(mb_substr($message, 0, $maxLength));
    }
}
