<?php

namespace App\Http\Controllers\Settings\Integrations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\TestHikvisionConnectionRequest;
use App\Http\Requests\Settings\UpdateHikvisionIntegrationRequest;
use App\Models\HikvisionSetting;
use App\Models\User;
use App\Services\HikvisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

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

        return [
            'settings' => $settings->toSettingsPageArray(),
            'can' => [
                'update' => $user->can('settings.integrations.hikvision.update'),
            ],
        ];
    }

    public function update(UpdateHikvisionIntegrationRequest $request): RedirectResponse
    {
        HikvisionSetting::current()->storeFromValidated($request->settingsPayload());

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
}
