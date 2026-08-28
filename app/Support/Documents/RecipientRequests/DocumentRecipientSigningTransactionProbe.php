<?php

namespace App\Support\Documents\RecipientRequests;

final class DocumentRecipientSigningTransactionProbe
{
    /** @var (\Closure(): void)|null */
    public static ?\Closure $afterLibrarySync = null;

    public static function afterLibrarySync(): void
    {
        if (self::$afterLibrarySync instanceof \Closure) {
            (self::$afterLibrarySync)();
        }
    }

    public static function reset(): void
    {
        self::$afterLibrarySync = null;
    }
}
