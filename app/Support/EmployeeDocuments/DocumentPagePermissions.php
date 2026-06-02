<?php

namespace App\Support\EmployeeDocuments;

use App\Models\User;
use App\Models\WhatsAppSetting;

class DocumentPagePermissions
{
    /**
     * @return array{download: bool, share: bool, delete: bool, whatsapp_template: bool}
     */
    public static function for(?User $user): array
    {
        $canView = $user?->can('documents.view') ?? false;

        return [
            'download' => $user?->can('documents.download') ?? false,
            'share' => $user?->can('documents.share') ?? false,
            'delete' => $user?->can('documents.delete') ?? false,
            'whatsapp_template' => $canView && WhatsAppSetting::current()->isConfigured(),
        ];
    }
}
