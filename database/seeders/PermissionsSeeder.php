<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'settings.security.view',
            'settings.security.update',
            'settings.appearance.view',
            'settings.application.view',
            'settings.application.update',
            'settings.integrations.whatsapp.view',
            'settings.integrations.whatsapp.update',
            'settings.integrations.hikvision.view',
            'settings.integrations.hikvision.update',
            'hikvision.persons.view',
            'hikvision.persons.sync',
            'hikvision.persons.create',
            'hikvision.persons.update',
            'hikvision.persons.delete',
            'hikvision.persons.link',
            'hikvision.webhook.manage',
            'hikvision.devices.view',
            'hikvision.devices.sync',
            'hikvision.events.view',
            'hikvision.events.fetch',
            'settings.integrations.whatsapp-templates.view',
            'settings.integrations.whatsapp-templates.create',
            'settings.integrations.whatsapp-templates.update',
            'settings.integrations.whatsapp-templates.delete',
            'settings.integrations.email-templates.view',
            'settings.integrations.email-templates.create',
            'settings.integrations.email-templates.update',
            'settings.integrations.email-templates.delete',
            'settings.master-data.countries.view',
            'settings.master-data.countries.create',
            'settings.master-data.countries.update',
            'settings.master-data.countries.delete',
            'settings.master-data.currencies.view',
            'settings.master-data.currencies.create',
            'settings.master-data.currencies.update',
            'settings.master-data.currencies.delete',

            'settings.master-data.visa-types.view',
            'settings.master-data.visa-types.create',
            'settings.master-data.visa-types.update',
            'settings.master-data.visa-types.delete',

            'settings.master-data.company-visa-types.view',
            'settings.master-data.company-visa-types.create',
            'settings.master-data.company-visa-types.update',
            'settings.master-data.company-visa-types.delete',

            'settings.master-data.approval-locations.view',
            'settings.master-data.approval-locations.create',
            'settings.master-data.approval-locations.update',
            'settings.master-data.approval-locations.delete',

            'settings.master-data.sssa-options.view',
            'settings.master-data.sssa-options.create',
            'settings.master-data.sssa-options.update',
            'settings.master-data.sssa-options.delete',

            'settings.master-data.religions.view',
            'settings.master-data.religions.create',
            'settings.master-data.religions.update',
            'settings.master-data.religions.delete',

            'settings.master-data.genders.view',
            'settings.master-data.genders.create',
            'settings.master-data.genders.update',
            'settings.master-data.genders.delete',

            'settings.master-data.courses.view',
            'settings.master-data.courses.create',
            'settings.master-data.courses.update',
            'settings.master-data.courses.delete',

            'settings.master-data.banks.view',
            'settings.master-data.banks.create',
            'settings.master-data.banks.update',
            'settings.master-data.banks.delete',

            'settings.master-data.vessel-types.view',
            'settings.master-data.vessel-types.create',
            'settings.master-data.vessel-types.update',
            'settings.master-data.vessel-types.delete',

            'settings.master-data.ranks.view',
            'settings.master-data.ranks.create',
            'settings.master-data.ranks.update',
            'settings.master-data.ranks.delete',

            'settings.master-data.clients.view',
            'settings.master-data.clients.create',
            'settings.master-data.clients.update',
            'settings.master-data.clients.delete',

            'settings.master-data.document-types.view',
            'settings.master-data.document-types.create',
            'settings.master-data.document-types.update',
            'settings.master-data.document-types.delete',

            'companies.view',
            'companies.create',
            'companies.update',
            'companies.delete',
            'companies.export',

            'branches.view',
            'branches.create',
            'branches.update',
            'branches.delete',
            'branches.export',

            'departments.view',
            'departments.create',
            'departments.update',
            'departments.delete',
            'departments.export',

            'positions.view',
            'positions.create',
            'positions.update',
            'positions.delete',
            'positions.export',

            'roles.view',
            'roles.create',
            'roles.update',
            'roles.delete',
            'roles.export',

            'users.view',
            'users.create',
            'users.update',
            'users.delete',
            'users.export',

            'employees.view',
            'employees.create',
            'employees.update',
            'employees.delete',
            'employees.export',
            'employees.import',
            'employees.identity.import',
            'employees.contracts.import',
            'employees.bank_accounts.import',
            'employees.education.manage',

            'documents.view',
            'documents.download',
            'documents.share',
            'documents.upload',
            'documents.delete',
            'employees.contracts.manage',
            'employees.work_experience.manage',
            'employees.vaccination.manage',
            'employees.languages.manage',
            'employees.bank_accounts.manage',
            'employees.sea_service.manage',
            'employees.training.manage',

            'crew_operations.deployments.view',
            'crew_operations.deployments.manage',

            'audit.view',

            'employee_profile_templates.view',
            'employee_profile_templates.create',
            'employee_profile_templates.update',
            'employee_profile_templates.delete',
        ];

        foreach ($permissions as $name) {
            Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
