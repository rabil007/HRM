<?php

namespace App\Http\Controllers\Settings\Integrations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\TestHikvisionConnectionRequest;
use App\Http\Requests\Settings\UpdateHikvisionIntegrationRequest;
use App\Models\HikvisionDevice;
use App\Models\HikvisionSetting;
use App\Models\User;
use App\Services\HikvisionService;
use App\Support\Settings\ApplicationTimezone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class HikvisionIntegrationController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user?->can('settings.integrations.hikvision.view'), 403);

        $companyId = (int) $request->attributes->get('current_company_id');

        return Inertia::render('settings/integrations/hikvision', self::pageProps($user, $companyId) ?? [
            'settings' => HikvisionSetting::forCompany($companyId)->toSettingsPageArray(),
            'webhook_url' => null,
            'scheduler_timezone' => ApplicationTimezone::identifier(),
            'can' => [
                'update' => false,
                'webhook_manage' => false,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function pageProps(?User $user, int $companyId): ?array
    {
        if (! $user?->can('settings.integrations.hikvision.view')) {
            return null;
        }

        $settings = HikvisionSetting::forCompany($companyId);

        $props = [
            'settings' => $settings->toSettingsPageArray(),
            'webhook_url' => filled($settings->public_id)
                ? URL::route('webhooks.hikvision', ['publicIntegrationId' => $settings->public_id], absolute: true)
                : null,
            'scheduler_timezone' => ApplicationTimezone::identifier(),
            'can' => [
                'update' => $user->can('settings.integrations.hikvision.update'),
                'webhook_manage' => $user->can('hikvision.webhook.manage'),
            ],
        ];

        if ($user->can('hikvision.devices.view')) {
            $props['devices'] = self::devicesPageProps($user, $companyId);
        }

        return $props;
    }

    /**
     * @return array{
     *     items: list<array<string, mixed>>,
     *     last_synced_at: string|null,
     *     can: array{sync: bool}
     * }
     */
    public static function devicesPageProps(User $user, int $companyId): array
    {
        $lastSyncedAt = HikvisionDevice::query()->forCompany($companyId)->max('synced_at');

        return [
            'items' => HikvisionDevice::query()
                ->forCompany($companyId)
                ->orderBy('name')
                ->get()
                ->map(fn (HikvisionDevice $device) => $device->toPageArray())
                ->values()
                ->all(),
            'last_synced_at' => $lastSyncedAt ? (string) $lastSyncedAt : null,
            'can' => [
                'sync' => $user->can('hikvision.devices.sync'),
            ],
        ];
    }

    public function syncDevices(Request $request): RedirectResponse
    {
        try {
            $result = HikvisionService::forCompany((int) $request->attributes->get('current_company_id'))->syncDevices();

            return back()->with('success', $result['message']);
        } catch (RuntimeException $exception) {
            return back()->withErrors([
                'devices_sync' => $exception->getMessage(),
            ]);
        }
    }

    public function update(UpdateHikvisionIntegrationRequest $request): RedirectResponse
    {
        $settings = HikvisionSetting::resolveForUpdate((int) $request->attributes->get('current_company_id'));
        $settings->storeFromValidated($request->settingsPayload());

        if ($request->boolean('webhook_enabled') && ! filled($settings->webhook_verify_token)) {
            $settings->ensureWebhookVerifyToken();
        }

        return back()->with('success', 'Hikvision settings saved.');
    }

    public function testConnection(TestHikvisionConnectionRequest $request): JsonResponse
    {
        $override = $request->credentialsOverride();
        $stored = HikvisionSetting::forCompany((int) $request->attributes->get('current_company_id'));

        if (! $stored->exists) {
            return response()->json([
                'success' => false,
                'message' => 'Save Hikvision credentials for this company before testing the connection.',
            ], 422);
        }

        if (! filled($override['api_key'] ?? null)) {
            $override['api_key'] = $stored->api_key;
        }

        if (! filled($override['api_secret'] ?? null)) {
            $override['api_secret'] = $stored->api_secret;
        }

        $result = HikvisionService::forSetting($stored)->testConnection($override);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function registerWebhook(Request $request): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $settings = HikvisionSetting::forCompany($companyId);

        if (! $settings->exists || ! $settings->isConfigured()) {
            return back()->withErrors([
                'webhook' => 'Configure Hikvision API credentials before registering the webhook.',
            ]);
        }

        $callbackUrl = URL::route('webhooks.hikvision', ['publicIntegrationId' => $settings->public_id], absolute: true);

        try {
            $result = HikvisionService::forSetting($settings)->ensureWebhookConfigured($callbackUrl);

            return back()->with('success', $result['message']);
        } catch (RuntimeException $exception) {
            return back()->withErrors([
                'webhook' => $exception->getMessage(),
            ]);
        }
    }
}
