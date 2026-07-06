<?php

namespace App\Providers;

use App\Models\EmailTemplate;
use App\Services\SalaryDeclaration\RendersSalaryDeclarationPdf;
use App\Services\SalaryDeclaration\SalaryDeclarationPdfRenderer;
use App\Services\Settings\MailSettingsService;
use App\Services\Settings\SettingService;
use App\Support\Queue\JobRunRecorder;
use App\Support\Settings\SettingKey;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Artisan commands that run during deploy/CI before MySQL is available.
     *
     * @var list<string>
     */
    private const DEFER_SETTINGS_COMMANDS = [
        'package:discover',
        'config:cache',
        'config:clear',
        'route:cache',
        'route:clear',
        'view:cache',
        'view:clear',
        'event:cache',
        'event:clear',
        'optimize',
        'optimize:clear',
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(RendersSalaryDeclarationPdf::class, SalaryDeclarationPdfRenderer::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureApplicationSettings();
        $this->configureMailViews();
        $this->configurePasswordResetNotifications();
        $this->configureJobRunRecording();
    }

    protected function configureJobRunRecording(): void
    {
        $recorder = app(JobRunRecorder::class);

        Event::listen(JobProcessing::class, [$recorder, 'recordQueueStarting']);
        Event::listen(JobProcessed::class, [$recorder, 'recordQueueFinished']);
        Event::listen(JobFailed::class, [$recorder, 'recordQueueFailed']);
        Event::listen(ScheduledTaskStarting::class, [$recorder, 'recordScheduledStarting']);
        Event::listen(ScheduledTaskFinished::class, [$recorder, 'recordScheduledFinished']);
        Event::listen(ScheduledTaskFailed::class, [$recorder, 'recordScheduledFailed']);
    }

    protected function configureMailViews(): void
    {
        View::composer('mail.layout', function ($view): void {
            $view->with('mailBranding', app(SettingService::class)->mailBranding());
        });
    }

    protected function configurePasswordResetNotifications(): void
    {
        ResetPassword::toMailUsing(function (object $notifiable, string $token): MailMessage {
            $url = url(route('password.reset', [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ], false));

            $expireMinutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire');
            $mailBranding = app(SettingService::class)->mailBranding();
            $brandName = $mailBranding['brand_name'] ?? config('app.name');

            // Load password reset template from database
            $template = EmailTemplate::query()
                ->where('slug', 'password_reset')
                ->where('enabled', true)
                ->first();

            if ($template) {
                $subjectTemplate = $template->subject;
                $bodyTemplate = $template->body_html;
                $userName = $notifiable->name ?? 'User';

                $buttonHtml = '<table role="presentation" cellspacing="0" cellpadding="0" align="center" style="margin:20px auto 24px;">
                    <tr>
                        <td align="center" style="border-radius:12px;background-color:#2563eb;">
                            <a href="'.e($url).'" style="display:inline-block;padding:14px 32px;font-size:15px;font-weight:700;line-height:1;color:#ffffff;text-decoration:none;border-radius:12px;background-color:#2563eb;border:1px solid #2563eb;">
                                Reset password
                            </a>
                        </td>
                    </tr>
                </table>';

                // HTML replacements
                $htmlReplacements = [
                    '{{user_name}}' => e($userName),
                    '{{reset_url}}' => $buttonHtml,
                    '{{expire_minutes}}' => $expireMinutes,
                    '{{brand_name}}' => e($brandName),
                ];

                // Text replacements
                $textReplacements = [
                    '{{user_name}}' => $userName,
                    '{{reset_url}}' => $url,
                    '{{expire_minutes}}' => $expireMinutes,
                    '{{brand_name}}' => $brandName,
                ];

                $subject = str_replace(array_keys($textReplacements), array_values($textReplacements), $subjectTemplate);
                $bodyHtml = str_replace('{{reset_url}}', $buttonHtml, str_replace(
                    array_filter(array_keys($htmlReplacements), fn ($k) => $k !== '{{reset_url}}'),
                    array_filter(array_values($htmlReplacements), fn ($v) => $v !== $buttonHtml),
                    nl2br(e($bodyTemplate))
                ));
                $bodyText = str_replace(array_keys($textReplacements), array_values($textReplacements), $bodyTemplate);

                return (new MailMessage)
                    ->subject($subject)
                    ->view([
                        'html' => 'mail.reset-password',
                        'text' => 'mail.reset-password-text',
                    ], [
                        'url' => $url,
                        'userName' => $userName,
                        'expireMinutes' => $expireMinutes,
                        'mailBranding' => $mailBranding,
                        'body' => $bodyHtml,
                        'subject' => $subject,
                    ]);
            }

            // Fallback to original hardcoded notification
            return (new MailMessage)
                ->subject("Reset your password — {$brandName}")
                ->view([
                    'html' => 'mail.reset-password',
                    'text' => 'mail.reset-password-text',
                ], [
                    'url' => $url,
                    'userName' => $notifiable->name ?? null,
                    'expireMinutes' => $expireMinutes,
                    'mailBranding' => $mailBranding,
                ]);
        });
    }

    protected function configureApplicationSettings(): void
    {
        View::composer('app', function ($view): void {
            $view->with([
                'appName' => config('app.name', 'Laravel'),
                'faviconUrl' => null,
            ]);

            if ($this->shouldDeferApplicationSettings()) {
                return;
            }

            try {
                $settings = app(SettingService::class);

                if (! $settings->isReady()) {
                    return;
                }

                $view->with([
                    'appName' => $settings->appName(),
                    'faviconUrl' => $settings->fileUrl(SettingKey::Favicon),
                ]);
            } catch (\Throwable) {
                //
            }
        });

        if ($this->shouldDeferApplicationSettings()) {
            return;
        }

        try {
            $settings = app(SettingService::class);

            if (! $settings->isReady()) {
                return;
            }

            config([
                'app.name' => $settings->appName(),
                'mail.from.name' => $settings->appName(),
            ]);

            app(MailSettingsService::class)->applyToRuntimeConfig();
        } catch (\Throwable) {
            //
        }
    }

    protected function shouldDeferApplicationSettings(): bool
    {
        if (! $this->app->runningInConsole()) {
            return false;
        }

        $command = $_SERVER['argv'][1] ?? null;

        if (! is_string($command) || $command === '') {
            return false;
        }

        return in_array($command, self::DEFER_SETTINGS_COMMANDS, true);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): Password => app()->isProduction()
            ? Password::min(8)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : Password::min(8),
        );
    }
}
