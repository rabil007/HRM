<?php

namespace App\Support\EmployeeDocuments;

enum DocumentRequirementComplianceStatus: string
{
    case Valid = 'valid';
    case Expiring = 'expiring';
    case Expired = 'expired';
    case Missing = 'missing';

    public static function isValidFilter(?string $filter): bool
    {
        return in_array($filter, [
            'required',
            self::Valid->value,
            self::Expiring->value,
            self::Expired->value,
            self::Missing->value,
        ], true);
    }

    public static function fromExpiry(?DocumentExpiryStatus $expiry): self
    {
        if ($expiry === null || $expiry === DocumentExpiryStatus::Valid) {
            return self::Valid;
        }

        if ($expiry === DocumentExpiryStatus::Expired) {
            return self::Expired;
        }

        return self::Expiring;
    }
}
