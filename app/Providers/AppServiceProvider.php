<?php

namespace App\Providers;

use App\Services\Settings\SettingService;
use App\Support\Settings\SettingKey;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
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
        if (! Schema::hasTable('app_settings')) {
            return;
        }

        $settings = app(SettingService::class);

        config([
            'app.name' => $settings->appName(),
            'mail.from.name' => $settings->appName(),
        ]);

        View::composer('app', function ($view) use ($settings): void {
            $view->with([
                'appName' => $settings->appName(),
                'faviconUrl' => $settings->fileUrl(SettingKey::Favicon),
            ]);
        });
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
