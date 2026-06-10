<?php

namespace App\Http\Controllers\Settings\Integrations;

use App\Enums\WhatsAppTemplateCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\SendWhatsAppTestDocumentRequest;
use App\Http\Requests\Settings\SendWhatsAppTestDocumentTemplateRequest;
use App\Http\Requests\Settings\SendWhatsAppTestTemplateRequest;
use App\Http\Requests\Settings\SendWhatsAppTestTextRequest;
use App\Http\Requests\Settings\TestWhatsAppConnectionRequest;
use App\Http\Requests\Settings\UpdateWhatsAppIntegrationRequest;
use App\Models\User;
use App\Models\WhatsAppSetting;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsAppService;
use App\Support\Uploads\UploadedFileStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class WhatsAppIntegrationController extends Controller
{
    public function __construct(private WhatsAppService $whatsapp) {}

    /**
     * @return array<string, mixed>|null
     */
    public static function pageProps(?User $user): ?array
    {
        if (! $user?->can('settings.integrations.whatsapp.view')) {
            return null;
        }

        $settings = WhatsAppSetting::current();

        return [
            'settings' => $settings->toSettingsPageArray(),
            'callback_url' => route(config('whatsapp.webhook_route_name')),
            'default_test_message' => (string) config('whatsapp.test_message'),
            'default_preview_sample_name' => 'John Smith',
            'document_templates' => WhatsAppTemplate::query()
                ->enabled()
                ->forCategory(WhatsAppTemplateCategory::Document)
                ->where('header_type', 'document')
                ->orderByDesc('is_default')
                ->orderBy('sort_order')
                ->orderBy('label')
                ->get()
                ->map(fn (WhatsAppTemplate $template) => [
                    'slug' => $template->slug,
                    'label' => $template->label,
                    'meta_name' => $template->meta_name,
                    'is_default' => $template->is_default,
                ])
                ->values()
                ->all(),
            'can' => [
                'update' => $user->can('settings.integrations.whatsapp.update'),
            ],
        ];
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
                $file->getMimeType(),
            ),
        );
    }

    public function sendTestTemplate(SendWhatsAppTestTemplateRequest $request): JsonResponse
    {
        return $this->messageTestResponse(
            fn () => $this->whatsapp->sendTemplateMessage($request->validated('phone')),
        );
    }

    public function sendTestDocumentTemplate(SendWhatsAppTestDocumentTemplateRequest $request): JsonResponse
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

        $storedPath = UploadedFileStorage::store($file, 'whatsapp-test-templates', 'public');
        $documentUrl = $this->publicHttpsUrl(Storage::disk('public')->url($storedPath));

        return $this->messageTestResponse(
            fn () => $this->whatsapp->sendDocumentTemplate(
                $request->validated('phone'),
                trim((string) $request->validated('sample_name')) ?: 'Test Employee',
                $documentUrl,
                $file->getClientOriginalName(),
                $request->validated('template_slug') ?: (string) config('whatsapp.default_document_template.slug'),
            ),
        );
    }

    private function publicHttpsUrl(string $url): string
    {
        if (str_starts_with($url, 'http://')) {
            return 'https://'.substr($url, 7);
        }

        return $url;
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
