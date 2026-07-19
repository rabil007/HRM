<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\Integrations\WhatsAppIntegrationController;
use App\Http\Requests\Settings\TestApplicationMailRequest;
use App\Http\Requests\Settings\UpdateApplicationBrandingRequest;
use App\Http\Requests\Settings\UpdateApplicationGeneralRequest;
use App\Http\Requests\Settings\UpdateApplicationSmtpRequest;
use App\Services\Settings\MailSettingsService;
use App\Services\Settings\SettingService;
use App\Support\BulkDocuments\BulkDocumentSignaturePlacementService;
use App\Support\BulkDocuments\BulkDocumentTypeRegistry;
use App\Support\BulkDocuments\SalaryDeclarationSignaturePlacements;
use App\Support\Settings\SettingKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ApplicationSettingsController extends Controller
{
    public function __construct(
        private SettingService $settings,
        private MailSettingsService $mailSettings,
    ) {}

    public function edit(): Response
    {
        $user = request()->user();
        $canPlatformView = $user?->can('platform.settings.view') || $user?->can('settings.application.view');
        $canWhatsAppView = $user?->can('settings.integrations.whatsapp.view');

        if (! $canPlatformView && ! $canWhatsAppView) {
            abort(403);
        }

        $props = [
            'scope' => 'platform',
            'general' => null,
            'branding' => null,
            'preferences' => null,
            'timezones' => null,
            'date_formats' => null,
            'smtp' => null,
            'whatsapp' => null,
            'esign_placement' => null,
            'can' => [
                'platform_view' => (bool) $canPlatformView,
                'platform_update' => (bool) (
                    $user?->can('platform.settings.update')
                    || $user?->can('settings.application.update')
                ),
                'whatsapp_view' => (bool) $canWhatsAppView,
            ],
        ];

        if ($canPlatformView) {
            $props['general'] = [
                'app_name' => $this->settings->get(SettingKey::AppName),
                'support_email' => $this->settings->get(SettingKey::SupportEmail, ''),
                'support_phone' => $this->settings->get(SettingKey::SupportPhone, ''),
                'timezone' => $this->settings->get(SettingKey::Timezone, 'UTC'),
                'date_format' => $this->settings->get(SettingKey::DateFormat, 'Y-m-d'),
            ];
            $props['branding'] = $this->settings->brandingUrls();
            $props['preferences'] = [
                'primary_color' => $this->settings->get(SettingKey::PrimaryColor, '#6366f1'),
                'accent_color' => $this->settings->get(SettingKey::AccentColor, '#8b5cf6'),
                'sidebar_compact_default' => $this->settings->get(SettingKey::SidebarCompactDefault, '0') === '1',
            ];
            $props['timezones'] = timezone_identifiers_list();
            $props['date_formats'] = [
                ['value' => 'Y-m-d', 'label' => '2026-05-21'],
                ['value' => 'd/m/Y', 'label' => '21/05/2026'],
                ['value' => 'm/d/Y', 'label' => '05/21/2026'],
                ['value' => 'd-m-Y', 'label' => '21-05-2026'],
                ['value' => 'M d, Y', 'label' => 'May 21, 2026'],
            ];
            $props['smtp'] = $this->mailSettings->forSettingsPage();
            $props['esign_placement'] = [
                'document_type' => SalaryDeclarationSignaturePlacements::DOCUMENT_TYPE_KEY,
                'label' => BulkDocumentTypeRegistry::find(SalaryDeclarationSignaturePlacements::DOCUMENT_TYPE_KEY)['label'],
                'placement' => app(BulkDocumentSignaturePlacementService::class)->resolve(
                    SalaryDeclarationSignaturePlacements::DOCUMENT_TYPE_KEY,
                ),
            ];
        }

        if ($canWhatsAppView) {
            $props['whatsapp'] = WhatsAppIntegrationController::pageProps($user);
        }

        return Inertia::render('settings/application', $props);
    }

    public function updateGeneral(UpdateApplicationGeneralRequest $request): RedirectResponse
    {
        $payload = $request->settingPayload();
        $this->settings->setMany($payload);

        activity()
            ->causedBy($request->user())
            ->withProperties([
                'scope' => 'platform',
                'keys' => array_keys($payload),
                'values' => [
                    SettingKey::AppName => $payload[SettingKey::AppName] ?? null,
                    SettingKey::SupportEmail => $payload[SettingKey::SupportEmail] ?? null,
                    SettingKey::SupportPhone => $payload[SettingKey::SupportPhone] ?? null,
                    SettingKey::Timezone => $payload[SettingKey::Timezone] ?? null,
                    SettingKey::DateFormat => $payload[SettingKey::DateFormat] ?? null,
                ],
            ])
            ->log('updated platform general settings');

        return back()->with('success', 'Platform settings saved.');
    }

    public function updateBranding(UpdateApplicationBrandingRequest $request): RedirectResponse
    {
        foreach ($request->uploadFiles() as $key => $file) {
            $this->settings->storeUpload($key, $file);
        }

        $colors = $request->colorPayload();

        if ($colors !== []) {
            $this->settings->setMany($colors);
        }

        return back()->with('success', 'Branding settings saved.');
    }

    public function removeBranding(string $asset): RedirectResponse
    {
        if (! in_array($asset, SettingKey::platformBrandingFileKeys(), true)) {
            abort(404);
        }

        $this->settings->deleteFile($asset);

        return back()->with('success', 'Image removed.');
    }

    public function updateSmtp(UpdateApplicationSmtpRequest $request): RedirectResponse
    {
        if ($request->hasFile('email_branding_logo')) {
            $this->settings->storeUpload(SettingKey::EmailBrandingLogo, $request->file('email_branding_logo'));
        }

        $this->mailSettings->storeFromPayload(
            $request->smtpPayload(),
            $request->emailFooterPayload(),
        );

        return back()->with('success', 'Email settings saved.');
    }

    public function sendTestMail(TestApplicationMailRequest $request): JsonResponse
    {
        $request->assertCanSend();

        $recipient = $request->validated('recipient');

        try {
            $this->mailSettings->sendTestEmail(
                $recipient,
                $request->smtpOverride(),
                (string) $request->validated('subject', ''),
                (string) $request->validated('body', ''),
                $request->file('attachment'),
            );
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'recipient' => 'Unable to send test email: '.$exception->getMessage(),
            ]);
        }

        return response()->json([
            'message' => "Test email sent to {$recipient}.",
        ]);
    }
}
