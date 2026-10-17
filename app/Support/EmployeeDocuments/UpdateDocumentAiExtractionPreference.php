<?php

namespace App\Support\EmployeeDocuments;

use App\Models\User;

final class UpdateDocumentAiExtractionPreference
{
    public const VALUE_ON = 'on';

    public const VALUE_OFF = 'off';

    /**
     * Persist the authenticated user's Document AI extraction toggle.
     * Values are limited to on/off; null means "use company-mode default".
     */
    public function handle(User $user, bool $enabled): void
    {
        $value = $enabled ? self::VALUE_ON : self::VALUE_OFF;

        if ($user->document_ai_extraction_preference === $value) {
            return;
        }

        $user->forceFill([
            'document_ai_extraction_preference' => $value,
        ])->save();
    }

    public static function normalize(?string $value): ?string
    {
        if ($value === self::VALUE_ON || $value === self::VALUE_OFF) {
            return $value;
        }

        return null;
    }
}
