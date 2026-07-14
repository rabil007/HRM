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
use Illuminate\Support\Facades\URL;
use RuntimeException;

class HikvisionIntegrationController extends Controller
{
    public function __construct(private HikvisionService $hikvision) {}

    /**
     * @return array<string, mixed>|null
     */
    public static function pageProps(?User $user): ?array
    {
        if (! $user?->can('settings.integrations.hikvision.view')) {
            return null;
        }

        $settings = HikvisionSetting::current();

        $props = [
            'settings' => $settings->toSettingsPageArray(),
            'webhook_url' => URL::route('webhooks.hikvision', absolute: true),
            'scheduler_timezone' => ApplicationTimezone::identifier(),
            'can' => [
                'update' => $user->can('settings.integrations.hikvision.update'),
                'webhook_manage' => $user->can('hikvision.webhook.manage'),
            ],
        ];

        if ($user->can('hikvision.devices.view')) {
            $props['devices'] = self::devicesPageProps($user);
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
    public static function devicesPageProps(User $user): array
    {
        $lastSyncedAt = HikvisionDevice::query()->max('synced_at');

        return [
            'items' => HikvisionDevice::query()
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

    public function syncDevices(): RedirectResponse
    {
        try {
            $result = $this->hikvision->syncDevices();

            return back()->with('success', $result['message']);
        } catch (RuntimeException $exception) {
            return back()->withErrors([
                'devices_sync' => $exception->getMessage(),
            ]);
        }
    }

    public function update(UpdateHikvisionIntegrationRequest $request): RedirectResponse
    {
        $settings = HikvisionSetting::current();
        $settings->storeFromValidated($request->settingsPayload());

        if ($request->boolean('webhook_enabled') && ! filled($settings->webhook_verify_token)) {
            $settings->ensureWebhookVerifyToken();
        }

        return back()->with('success', 'Hikvision settings saved.');
    }

    public function testConnection(TestHikvisionConnectionRequest $request): JsonResponse
    {
        $override = $request->credentialsOverride();
        $stored = HikvisionSetting::current();

        if (! filled($override['api_key'] ?? null)) {
            $override['api_key'] = $stored->api_key;
        }

        if (! filled($override['api_secret'] ?? null)) {
            $override['api_secret'] = $stored->api_secret;
        }

        $result = $this->hikvision->testConnection($override);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function registerWebhook(): RedirectResponse
    {
        $settings = HikvisionSetting::current();

        if (! $settings->isConfigured()) {
            return back()->withErrors([
                'webhook' => 'Configure Hikvision API credentials before registering the webhook.',
            ]);
        }

        $callbackUrl = URL::route('webhooks.hikvision', absolute: true);

        try {
            $result = $this->hikvision->ensureWebhookConfigured($callbackUrl);

            return back()->with('success', $result['message']);
        } catch (RuntimeException $exception) {
            return back()->withErrors([
                'webhook' => $exception->getMessage(),
            ]);
        }
    }
}
