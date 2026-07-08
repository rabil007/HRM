<?php

namespace App\Support\Email;

final class EmailTemplateBodyRenderer
{
    public static function toHtml(string $body): string
    {
        $trimmed = trim($body);

        if ($trimmed === '') {
            return '';
        }

        if (preg_match('/<[a-z][\s\S]*>/i', $trimmed)) {
            return $trimmed;
        }

        return nl2br(e($trimmed));
    }
}
