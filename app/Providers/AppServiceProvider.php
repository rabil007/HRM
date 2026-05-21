<?php

namespace App\Providers;

use App\Services\Settings\SettingService;
use App\Support\Settings\SettingKey;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureApplicationSettings();
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

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
