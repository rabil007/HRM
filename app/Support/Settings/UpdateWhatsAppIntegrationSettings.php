<?php

namespace App\Support\Settings;

use App\Models\User;
use App\Models\WhatsAppSetting;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

final class UpdateWhatsAppIntegrationSettings
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(WhatsAppSetting $settings, array $data, User $actor, ?int $companyId = null): void
    {
        DB::transaction(function () use ($settings, $data, $actor, $companyId): void {
            $credentialKeys = collect([
                'access_token',
                'app_secret',
                'webhook_verify_token',
            ])->filter(fn (string $key): bool => filled($data[$key] ?? null))
                ->values()
                ->all();

            $settings->storeFromValidated($data);

            activity('platform')
                ->event('updated')
                ->causedBy($actor)
                ->performedOn($settings)
                ->withProperties([
                    'scope' => 'platform',
                    'business_account_id' => $settings->business_account_id,
                    'phone_number_id' => $settings->phone_number_id,
                    'app_id' => $settings->app_id,
                    'enabled' => (bool) $settings->enabled,
                    'credentials_updated' => $credentialKeys,
                    'has_access_token' => filled($settings->access_token),
                    'has_app_secret' => filled($settings->app_secret),
                    'has_webhook_verify_token' => filled($settings->webhook_verify_token),
                ])
                ->tap(function (Activity $activity) use ($companyId): void {
                    $activity->company_id = $companyId && $companyId > 0 ? (int) $companyId : null;
                })
                ->log('WhatsApp integration settings updated');
        });
    }
}
