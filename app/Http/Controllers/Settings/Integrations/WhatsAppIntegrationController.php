<?php

namespace App\Http\Controllers\Settings\Integrations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\SendWhatsAppTestDocumentRequest;
use App\Http\Requests\Settings\SendWhatsAppTestTextRequest;
use App\Http\Requests\Settings\TestWhatsAppConnectionRequest;
use App\Http\Requests\Settings\UpdateWhatsAppIntegrationRequest;
use App\Models\WhatsAppSetting;
use App\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class WhatsAppIntegrationController extends Controller
{
    public function __construct(private WhatsAppService $whatsapp) {}

    public function edit(): Response
    {
        $settings = WhatsAppSetting::current();

        return Inertia::render('settings/integrations/whatsapp', [
            'settings' => $settings->toSettingsPageArray(),
            'callback_url' => route(config('whatsapp.webhook_route_name')),
            'default_test_message' => (string) config('whatsapp.test_message'),
            'can' => [
                'update' => request()->user()?->can('settings.integrations.whatsapp.update') ?? false,
            ],
        ]);
    }

    public function update(UpdateWhatsAppIntegrationRequest $request): RedirectResponse
    {
        WhatsAppSetting::current()->storeFromValidated($request->settingsPayload());

        return back()->with('success', 'WhatsApp settings saved.');
    }

    public function testConnection(TestWhatsAppConnectionRequest $request): JsonResponse
    {
        $override = $request->credentialsOverride();
        $stored = WhatsAppSetting::current();

        if (! filled($override['access_token'] ?? null)) {
            $override['access_token'] = $stored->access_token;
        }

        if (! filled($override['app_secret'] ?? null)) {
            $override['app_secret'] = $stored->app_secret;
        }

        $result = $this->whatsapp->testConnection($override);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function sendTestText(SendWhatsAppTestTextRequest $request): JsonResponse
    {
        return $this->messageTestResponse(
            fn () => $this->whatsapp->sendTextMessage(
                $request->validated('phone'),
                $request->validated('message'),
            ),
        );
    }

    public function sendTestDocument(SendWhatsAppTestDocumentRequest $request): JsonResponse
    {
        $file = $request->file('file');

        if ($file === null) {
            return response()->json([
                'success' => false,
                'message' => 'A file is required.',
                'message_id' => null,
                'http_status' => 422,
                'data' => null,
            ], 422);
        }

        return $this->messageTestResponse(
            fn () => $this->whatsapp->sendDocument(
                $request->validated('phone'),
                $file->getRealPath(),
                $file->getClientOriginalName(),
                $request->validated('caption'),
            ),
        );
    }

    /**
     * @param  callable(): array<string, mixed>  $callback
     */
    private function messageTestResponse(callable $callback): JsonResponse
    {
        try {
            $result = $callback();
        } catch (RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'message_id' => null,
                'http_status' => 422,
                'data' => null,
            ], 422);
        }

        return response()->json($result, $result['success'] ? 200 : 422);
    }
}
