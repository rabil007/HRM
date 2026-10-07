<?php

namespace App\Support\Recruitment;

/**
 * Opaque delivery identity for Target Date reminder idempotency.
 * Primary recipient is keyed by user id so email changes do not resend.
 */
final class RequirementTargetDateReminderDeliveryKey
{
    public static function forUser(int $userId): string
    {
        return hash('sha256', 'user:'.$userId);
    }
}
