<?php

namespace App\Support\Documents\Signing;

use App\Enums\DocumentRecipientRole;
use InvalidArgumentException;

/**
 * A signature slot identifies one logical signing obligation.
 *
 * Recipient role answers "what kind of signer?". Slot answers "which signing
 * action?". One slot may own one or more physical template signature placements.
 *
 * Persisted request keys (subject, manager_1, company_signatory_1, …) are
 * unchanged; they do not identify a single PDF box.
 */
final class DocumentSignatureSlot
{
    public const SUBJECT = 'subject';

    public const MAX_OCCURRENCE = 7;

    public static function forRoleOccurrence(DocumentRecipientRole $role, int $occurrence = 1): string
    {
        if ($role === DocumentRecipientRole::Subject) {
            if ($occurrence !== 1) {
                throw new InvalidArgumentException('Subject signature slot occurrence must be 1.');
            }

            return self::SUBJECT;
        }

        if ($occurrence < 1 || $occurrence > self::MAX_OCCURRENCE) {
            throw new InvalidArgumentException('Signature slot occurrence is out of range.');
        }

        return match ($role) {
            DocumentRecipientRole::Manager => "manager_{$occurrence}",
            DocumentRecipientRole::CompanySignatory => "company_signatory_{$occurrence}",
            default => throw new InvalidArgumentException('Unsupported signature slot role.'),
        };
    }

    public static function defaultForRole(DocumentRecipientRole $role): string
    {
        return self::forRoleOccurrence($role, 1);
    }

    /**
     * @return array{role: DocumentRecipientRole, occurrence: int}
     */
    public static function parse(string $slotKey): array
    {
        $slotKey = trim($slotKey);

        if ($slotKey === self::SUBJECT) {
            return [
                'role' => DocumentRecipientRole::Subject,
                'occurrence' => 1,
            ];
        }

        if (preg_match('/^manager_(\d+)$/', $slotKey, $matches) === 1) {
            $occurrence = (int) $matches[1];

            if ($occurrence < 1 || $occurrence > self::MAX_OCCURRENCE) {
                throw new InvalidArgumentException('Unsupported manager signature slot.');
            }

            return [
                'role' => DocumentRecipientRole::Manager,
                'occurrence' => $occurrence,
            ];
        }

        if (preg_match('/^company_signatory_(\d+)$/', $slotKey, $matches) === 1) {
            $occurrence = (int) $matches[1];

            if ($occurrence < 1 || $occurrence > self::MAX_OCCURRENCE) {
                throw new InvalidArgumentException('Unsupported company signatory signature slot.');
            }

            return [
                'role' => DocumentRecipientRole::CompanySignatory,
                'occurrence' => $occurrence,
            ];
        }

        throw new InvalidArgumentException('Unsupported signature slot key.');
    }

    public static function isValid(string $slotKey): bool
    {
        try {
            self::parse($slotKey);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public static function roleFor(string $slotKey): DocumentRecipientRole
    {
        return self::parse($slotKey)['role'];
    }

    public static function occurrenceFor(string $slotKey): int
    {
        return self::parse($slotKey)['occurrence'];
    }

    public static function defaultLabel(DocumentRecipientRole $role, int $occurrence = 1): string
    {
        return match ($role) {
            DocumentRecipientRole::Subject => 'Employee',
            DocumentRecipientRole::Manager => $occurrence === 1
                ? 'Department Manager'
                : "Management level {$occurrence}",
            DocumentRecipientRole::CompanySignatory => $occurrence === 1
                ? 'Company Signatory'
                : "Company Signatory {$occurrence}",
            default => $role->value,
        };
    }

    public static function placementIdFor(string $slotKey): string
    {
        $parsed = self::parse($slotKey);

        return match ($parsed['role']) {
            DocumentRecipientRole::Subject => 'subject_signature',
            DocumentRecipientRole::Manager => $parsed['occurrence'] === 1
                ? 'manager_signature'
                : "manager_signature_{$parsed['occurrence']}",
            DocumentRecipientRole::CompanySignatory => $parsed['occurrence'] === 1
                ? 'company_signatory_signature'
                : "company_signatory_signature_{$parsed['occurrence']}",
            default => $slotKey.'_signature',
        };
    }
}
