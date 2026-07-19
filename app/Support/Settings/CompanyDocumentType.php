<?php

namespace App\Support\Settings;

final class CompanyDocumentType
{
    public const SalaryCertificate = 'salary_certificate';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::SalaryCertificate,
        ];
    }

    public static function isValid(string $type): bool
    {
        return in_array($type, self::all(), true);
    }
}
