<?php

namespace App\Support\Documents\RecipientRequests;

final class DocumentRecipientAcceptedFlag
{
    public static function isAccepted(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) === true;
    }
}
