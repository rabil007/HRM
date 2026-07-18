<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Models\Company;
use App\Models\CompanyDocumentSetting;
use App\Models\Currency;
use App\Support\Settings\CompanyDocumentType;
use App\Support\Settings\SettingKey;
use Illuminate\Console\Command;

class MigrateLegacyCompanySettingsCommand extends Command
{
    protected $signature = 'settings:migrate-legacy-company-settings {--force : Run even when multiple companies exist (copies nothing automatically)}';

    protected $description = 'Copy blank company fields and document assets from legacy global app settings (single-company installs only)';

    public function handle(): int
    {
        $companies = Company::query()->orderBy('id')->get();

        if ($companies->isEmpty()) {
            $this->warn('No companies found.');

            return self::SUCCESS;
        }

        if ($companies->count() > 1) {
            $this->warn('Multiple companies detected. Legacy global identity was not copied automatically.');
            $this->line('Configure each company under Organization → Companies, including salary certificate signature and stamp.');

            if (! $this->option('force')) {
                return self::SUCCESS;
            }

            $this->info('Force flag set — no automatic multi-company copy was performed.');

            return self::SUCCESS;
        }

        /** @var Company $company */
        $company = $companies->first();
        $settings = AppSetting::query()
            ->whereIn('key', [
                SettingKey::CompanyName,
                SettingKey::CompanyAddress,
                SettingKey::SupportEmail,
                SettingKey::SupportPhone,
                SettingKey::Timezone,
                SettingKey::Currency,
                SettingKey::SalaryCertificateSignature,
                SettingKey::SalaryCertificateStamp,
            ])
            ->pluck('value', 'key');

        $updates = [];

        if (! filled($company->name) && filled($settings[SettingKey::CompanyName] ?? null)) {
            $updates['name'] = (string) $settings[SettingKey::CompanyName];
        }

        if (! filled($company->address) && filled($settings[SettingKey::CompanyAddress] ?? null)) {
            $updates['address'] = (string) $settings[SettingKey::CompanyAddress];
        }

        if (! filled($company->email) && filled($settings[SettingKey::SupportEmail] ?? null)) {
            $updates['email'] = (string) $settings[SettingKey::SupportEmail];
        }

        if (! filled($company->phone) && filled($settings[SettingKey::SupportPhone] ?? null)) {
            $updates['phone'] = (string) $settings[SettingKey::SupportPhone];
        }

        if (! filled($company->timezone) && filled($settings[SettingKey::Timezone] ?? null)) {
            $timezone = (string) $settings[SettingKey::Timezone];

            if (in_array($timezone, timezone_identifiers_list(), true)) {
                $updates['timezone'] = $timezone;
            }
        }

        if (! filled($company->currency_id) && filled($settings[SettingKey::Currency] ?? null)) {
            $currencyId = Currency::query()
                ->where('code', (string) $settings[SettingKey::Currency])
                ->where('is_active', true)
                ->value('id');

            if ($currencyId) {
                $updates['currency_id'] = $currencyId;
            }
        }

        if ($updates !== []) {
            $company->fill($updates)->save();
            $this->info('Updated company fields: '.implode(', ', array_keys($updates)));
        } else {
            $this->line('No blank company identity fields needed updating.');
        }

        $exists = CompanyDocumentSetting::query()
            ->where('company_id', $company->id)
            ->where('document_type', CompanyDocumentType::SalaryCertificate)
            ->exists();

        if ($exists) {
            $this->line('Company document settings already exist; left unchanged.');

            return self::SUCCESS;
        }

        $signature = $settings[SettingKey::SalaryCertificateSignature] ?? null;
        $stamp = $settings[SettingKey::SalaryCertificateStamp] ?? null;

        if (! filled($signature) && ! filled($stamp)) {
            $this->line('No legacy signature/stamp to copy.');

            return self::SUCCESS;
        }

        CompanyDocumentSetting::query()->create([
            'company_id' => $company->id,
            'document_type' => CompanyDocumentType::SalaryCertificate,
            'signature_path' => filled($signature) ? (string) $signature : null,
            'stamp_path' => filled($stamp) ? (string) $stamp : null,
        ]);

        $this->info('Created salary certificate document settings from legacy assets (paths referenced, files not duplicated).');

        return self::SUCCESS;
    }
}
