<?php

namespace App\Support\Settings;

use App\Models\User;

final class SettingsHubAccess
{
    /**
     * @return list<string>
     */
    public static function viewPermissions(): array
    {
        return [
            'settings.application.view',
            'settings.security.view',
            'settings.appearance.view',
            'settings.integrations.whatsapp.view',
            'settings.integrations.hikvision.view',
            'settings.integrations.whatsapp-templates.view',
            'settings.integrations.email-templates.view',
            'settings.master-data.countries.view',
            'settings.master-data.currencies.view',
            'settings.master-data.visa-types.view',
            'settings.master-data.company-visa-types.view',
            'settings.master-data.approval-locations.view',
            'settings.master-data.sssa-options.view',
            'settings.master-data.religions.view',
            'settings.master-data.genders.view',
            'settings.master-data.courses.view',
            'settings.master-data.banks.view',
            'settings.master-data.vessel-types.view',
            'settings.master-data.vessels.view',
            'settings.master-data.ranks.view',
            'settings.master-data.clients.view',
            'settings.master-data.document-types.view',
            'settings.master-data.projects.view',
        ];
    }

    public function allowed(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        foreach (self::viewPermissions() as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }
}
