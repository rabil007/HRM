<?php

namespace App\Support\EmployeeDocuments\DocumentExpiryNotification;

/**
 * Opaque, stable recipient identities for employee document expiry email dedupe.
 *
 * Internal users are keyed by user id (not email) so address changes do not
 * resend the same expiry event. Manual recipients use normalized email.
 */
final class DocumentExpiryRecipientDeliveryKey
{
    public static function forUser(int $userId): string
    {
        return hash('sha256', 'user:'.$userId);
    }

    public static function forEmail(string $email): string
    {
        return hash('sha256', 'email:'.strtolower(trim($email)));
    }
}
