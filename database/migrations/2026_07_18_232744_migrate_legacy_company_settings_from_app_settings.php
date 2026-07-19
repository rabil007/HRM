<?php

use App\Models\AppSetting;
use App\Models\Company;
use App\Models\CompanyDocumentSetting;
use App\Models\Currency;
use App\Support\Settings\CompanyDocumentType;
use App\Support\Settings\SettingKey;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $companies = Company::query()->orderBy('id')->get();

        if ($companies->count() !== 1) {
            return;
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
            $company->fill($updates);
            $company->save();
        }

        $exists = CompanyDocumentSetting::query()
            ->where('company_id', $company->id)
            ->where('document_type', CompanyDocumentType::SalaryCertificate)
            ->exists();

        if ($exists) {
            return;
        }

        $signature = $settings[SettingKey::SalaryCertificateSignature] ?? null;
        $stamp = $settings[SettingKey::SalaryCertificateStamp] ?? null;

        if (! filled($signature) && ! filled($stamp)) {
            return;
        }

        CompanyDocumentSetting::query()->create([
            'company_id' => $company->id,
            'document_type' => CompanyDocumentType::SalaryCertificate,
            'signature_path' => filled($signature) ? (string) $signature : null,
            'stamp_path' => filled($stamp) ? (string) $stamp : null,
        ]);
    }

    public function down(): void
    {
        $companies = Company::query()->orderBy('id')->get();

        if ($companies->count() !== 1) {
            return;
        }

        $company = $companies->first();

        CompanyDocumentSetting::query()
            ->where('company_id', $company->id)
            ->where('document_type', CompanyDocumentType::SalaryCertificate)
            ->where(function ($query): void {
                $legacySignature = AppSetting::query()
                    ->where('key', SettingKey::SalaryCertificateSignature)
                    ->value('value');
                $legacyStamp = AppSetting::query()
                    ->where('key', SettingKey::SalaryCertificateStamp)
                    ->value('value');

                $query->where(function ($inner) use ($legacySignature, $legacyStamp): void {
                    if (filled($legacySignature)) {
                        $inner->orWhere('signature_path', $legacySignature);
                    }

                    if (filled($legacyStamp)) {
                        $inner->orWhere('stamp_path', $legacyStamp);
                    }
                });
            })
            ->delete();
    }
};
