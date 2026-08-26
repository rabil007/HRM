<?php

namespace App\Support\Employees;

final class EmployeeSmartSearchPromptGuard
{
    public const MESSAGE = 'Use the regular Employee search for a specific name, employee number, email, phone, passport or personal identifier. Smart Search is for categories and filter conditions.';

    public static function shouldBlock(string $prompt): bool
    {
        $normalized = trim($prompt);

        if ($normalized === '') {
            return false;
        }

        return self::containsEmailAddress($normalized)
            || self::containsEmiratesId($normalized)
            || self::containsSpecificPhone($normalized)
            || self::containsSpecificPassport($normalized)
            || self::containsEmployeeNumberLookup($normalized)
            || self::containsNamedPersonLookup($normalized);
    }

    private static function containsEmailAddress(string $prompt): bool
    {
        return preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $prompt) === 1;
    }

    private static function containsEmiratesId(string $prompt): bool
    {
        if (preg_match('/\b784[\s\-]?\d{4}[\s\-]?\d{7}[\s\-]?\d\b/', $prompt) === 1) {
            return true;
        }

        return preg_match('/\b784\d{12}\b/', $prompt) === 1;
    }

    private static function containsSpecificPhone(string $prompt): bool
    {
        if (preg_match('/\b(?:phone|mobile|whatsapp|tel|telephone)\b/i', $prompt) !== 1) {
            return preg_match('/(?:\+|00)\d[\d\s\-()]{8,}\d/', $prompt) === 1;
        }

        return preg_match('/\d[\d\s\-()]{6,}\d/', $prompt) === 1;
    }

    private static function containsSpecificPassport(string $prompt): bool
    {
        if (preg_match('/\bpassport\b/i', $prompt) !== 1) {
            return false;
        }

        if (preg_match('/\b(?:without|no|missing|empty|blank|with|have|has|filled)\b/i', $prompt) === 1
            && preg_match('/\b[A-Z]{1,2}\d{6,9}\b/i', $prompt) !== 1) {
            return false;
        }

        return preg_match('/\b[A-Z]{1,2}\d{6,9}\b/i', $prompt) === 1;
    }

    private static function containsEmployeeNumberLookup(string $prompt): bool
    {
        return preg_match(
            '/\b(?:employee\s*(?:no|number|id|#)|emp(?:loyee)?\s*(?:no|id|#))\b[^.]{0,20}\b(?!missing|without|empty)[A-Z0-9][A-Z0-9\-\/]{1,}/i',
            $prompt,
        ) === 1;
    }

    private static function containsNamedPersonLookup(string $prompt): bool
    {
        return preg_match(
            '/\b(?:named|name\s+is|whose\s+name\s+is|called)\s+[A-Z][A-Za-z\'\-]+(?:\s+[A-Z][A-Za-z\'\-]+)?\b/',
            $prompt,
        ) === 1;
    }
}
