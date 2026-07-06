<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\Integrations\HikvisionIntegrationController;
use App\Http\Controllers\Settings\Integrations\WhatsAppIntegrationController;
use App\Http\Requests\Settings\TestApplicationMailRequest;
use App\Http\Requests\Settings\UpdateApplicationBrandingRequest;
use App\Http\Requests\Settings\UpdateApplicationGeneralRequest;
use App\Http\Requests\Settings\UpdateApplicationSmtpRequest;
use App\Models\Currency;
use App\Services\Settings\MailSettingsService;
use App\Services\Settings\SettingService;
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

        if (
            ! $user?->can('settings.application.view')
            && ! $user?->can('settings.application.bulk-documents')
            && ! $user?->can('settings.integrations.whatsapp.view')
            && ! $user?->can('settings.integrations.hikvision.view')
        ) {
            abort(403);
        }

        $currencies = Currency::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['code', 'name', 'symbol']);

        return Inertia::render('settings/application', [
            'general' => [
                'app_name' => $this->settings->get(SettingKey::AppName),
                'company_name' => $this->settings->get(SettingKey::CompanyName),
                'support_email' => $this->settings->get(SettingKey::SupportEmail, ''),
                'support_phone' => $this->settings->get(SettingKey::SupportPhone, ''),
                'company_address' => $this->settings->get(SettingKey::CompanyAddress, ''),
                'timezone' => $this->settings->get(SettingKey::Timezone, 'UTC'),
                'currency' => $this->settings->get(SettingKey::Currency, 'USD'),
                'date_format' => $this->settings->get(SettingKey::DateFormat, 'Y-m-d'),
                'salary_certificate_signature_url' => $this->settings->fileUrl(SettingKey::SalaryCertificateSignature),
                'salary_certificate_stamp_url' => $this->settings->fileUrl(SettingKey::SalaryCertificateStamp),
            ],
            'branding' => $this->settings->brandingUrls(),
            'preferences' => [
                'primary_color' => $this->settings->get(SettingKey::PrimaryColor, '#6366f1'),
                'accent_color' => $this->settings->get(SettingKey::AccentColor, '#8b5cf6'),
                'sidebar_compact_default' => $this->settings->get(SettingKey::SidebarCompactDefault, '0') === '1',
            ],
            'timezones' => timezone_identifiers_list(),
            'date_formats' => [
                ['value' => 'Y-m-d', 'label' => '2026-05-21'],
                ['value' => 'd/m/Y', 'label' => '21/05/2026'],
                ['value' => 'm/d/Y', 'label' => '05/21/2026'],
                ['value' => 'd-m-Y', 'label' => '21-05-2026'],
                ['value' => 'M d, Y', 'label' => 'May 21, 2026'],
            ],
            'currencies' => $currencies,
            'smtp' => $this->mailSettings->forSettingsPage(),
            'whatsapp' => WhatsAppIntegrationController::pageProps($user),
            'hikvision' => HikvisionIntegrationController::pageProps($user),
        ]);
    }

    public function updateGeneral(UpdateApplicationGeneralRequest $request): RedirectResponse
    {
        foreach ($request->uploadFiles() as $key => $file) {
            $this->settings->storeUpload($key, $file);
        }

        $this->settings->setMany($request->settingPayload());

        return back()->with('success', 'General settings saved.');
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
        if (! in_array($asset, SettingKey::fileKeys(), true)) {
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
