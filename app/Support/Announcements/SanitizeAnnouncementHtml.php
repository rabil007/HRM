<?php

namespace App\Support\Announcements;

use DOMDocument;
use DOMElement;
use DOMNode;

final class SanitizeAnnouncementHtml
{
    /**
     * @var list<string>
     */
    private const ALLOWED_TAGS = [
        'p',
        'br',
        'strong',
        'b',
        'em',
        'i',
        'u',
        's',
        'ul',
        'ol',
        'li',
        'a',
        'h2',
        'h3',
        'h4',
        'blockquote',
    ];

    /**
     * @var list<string>
     */
    private const REMOVE_WITH_CONTENT = [
        'script',
        'style',
        'iframe',
        'object',
        'embed',
        'svg',
        'math',
        'form',
        'input',
        'button',
        'textarea',
        'select',
        'option',
        'meta',
        'link',
        'base',
    ];

    public static function handle(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previousErrors = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8"><div data-announcement-root>'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);

        if (! $loaded) {
            return '';
        }

        $root = $document->getElementsByTagName('div')->item(0);

        if (! $root instanceof DOMElement) {
            return '';
        }

        self::sanitizeChildren($root);

        $cleaned = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $cleaned .= $document->saveHTML($child);
        }

        return trim($cleaned);
    }

    private static function sanitizeChildren(DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $tagName = strtolower($child->tagName);

            if (in_array($tagName, self::REMOVE_WITH_CONTENT, true)) {
                $parent->removeChild($child);

                continue;
            }

            self::sanitizeChildren($child);

            if (! in_array($tagName, self::ALLOWED_TAGS, true)) {
                while ($child->firstChild !== null) {
                    $parent->insertBefore($child->firstChild, $child);
                }

                $parent->removeChild($child);

                continue;
            }

            self::sanitizeAttributes($child);
        }
    }

    private static function sanitizeAttributes(DOMElement $element): void
    {
        $href = strtolower($element->tagName) === 'a'
            ? trim((string) $element->getAttribute('href'))
            : '';

        foreach (iterator_to_array($element->attributes) as $attribute) {
            $element->removeAttribute($attribute->name);
        }

        if (strtolower($element->tagName) !== 'a') {
            return;
        }

        if (! self::isSafeHref($href)) {
            return;
        }

        $element->setAttribute('href', $href);
        $element->setAttribute('target', '_blank');
        $element->setAttribute('rel', 'noopener noreferrer');
    }

    private static function isSafeHref(string $href): bool
    {
        if ($href === '' || str_starts_with($href, '//')) {
            return false;
        }

        if (str_starts_with($href, '#') || str_starts_with($href, '/')) {
            return true;
        }

        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https', 'mailto', 'tel'], true);
    }
}
