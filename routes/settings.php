<?php

use App\Http\Controllers\Settings\MasterData\CountryController;
use App\Http\Controllers\Settings\MasterData\CurrencyController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/security');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware('can:settings.security.view')
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->middleware('can:settings.security.update')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')
        ->middleware('can:settings.appearance.view')
        ->name('appearance.edit');

    Route::prefix('settings/master-data')->name('settings.master-data.')->group(function () {
        Route::get('countries', [CountryController::class, 'index'])
            ->middleware('can:settings.master-data.countries.view')
            ->name('countries.index');
        Route::post('countries', [CountryController::class, 'store'])
            ->middleware('can:settings.master-data.countries.create')
            ->name('countries.store');
        Route::put('countries/{country}', [CountryController::class, 'update'])
            ->middleware('can:settings.master-data.countries.update')
            ->name('countries.update');
        Route::delete('countries/{country}', [CountryController::class, 'destroy'])
            ->middleware('can:settings.master-data.countries.delete')
            ->name('countries.destroy');

        Route::get('currencies', [CurrencyController::class, 'index'])
            ->middleware('can:settings.master-data.currencies.view')
            ->name('currencies.index');
        Route::post('currencies', [CurrencyController::class, 'store'])
            ->middleware('can:settings.master-data.currencies.create')
            ->name('currencies.store');
        Route::put('currencies/{currency}', [CurrencyController::class, 'update'])
            ->middleware('can:settings.master-data.currencies.update')
            ->name('currencies.update');
        Route::delete('currencies/{currency}', [CurrencyController::class, 'destroy'])
            ->middleware('can:settings.master-data.currencies.delete')
            ->name('currencies.destroy');
    });
});
