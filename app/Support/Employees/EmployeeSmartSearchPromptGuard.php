<?php

namespace App\Support\Employees;

final class EmployeeSmartSearchPromptGuard
{
    public const MESSAGE = 'Use the regular Employee search for a specific name, employee number, email, phone, passport or personal identifier. Smart Search is for categories and filter conditions.';

    /**
     * A person-like token: two or more letters, optionally a second name word.
     * Organizational / completeness words are excluded so category phrases stay allowed.
     */
    private const ORGANIZATIONAL_WORD = 'department|dept|position|branch|rank|team|division|unit|crew|crewing';

    /**
     * A person-like token: two or more letters, optionally a second name word.
     * Word boundaries prevent backtracking into a partial token (for example
     * "Crewin" inside "Crewing department").
     */
    private const PERSON_NAME = "(?!(?:missing|without|empty|blank|present|filled|none|no|manager)\\b)[A-Za-z][A-Za-z'\\-]{1,}\\b(?:\\s+(?!(?:department|dept|position|branch|rank|team|division|unit|crew|crewing|missing|without|manager)\\b)[A-Za-z][A-Za-z'\\-]{1,}\\b)?";

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
            || self::containsNamedPersonLookup($normalized)
            || self::containsManagerPersonLookup($normalized);
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
            '/\b(?:named|name\s+is|whose\s+name\s+is|called)\s+'.self::PERSON_NAME.'\b/i',
            $prompt,
        ) === 1;
    }

    private static function containsManagerPersonLookup(string $prompt): bool
    {
        $person = self::PERSON_NAME;

        $patterns = [
            '/\bunder\s+(?!\d)(?!(?:'.self::ORGANIZATIONAL_WORD.')\b)'.$person.'(?!\s+(?:'.self::ORGANIZATIONAL_WORD.')\b)/i',
            '/\b(?:managed|supervised)\s+by\s+'.$person.'\b/i',
            '/\breport(?:s|ing)?\s+to\s+'.$person.'\b/i',
            '/\bwho\s+report(?:s)?\s+to\s+'.$person.'\b/i',
            '/\b(?:with\s+)?manager(?:\s+is)?\s+'.$person.'\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $prompt) === 1) {
                return true;
            }
        }

        return false;
    }
}
