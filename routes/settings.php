<?php

use App\Http\Controllers\Settings\MasterData\BankController;
use App\Http\Controllers\Settings\MasterData\CountryController;
use App\Http\Controllers\Settings\MasterData\CurrencyController;
use App\Http\Controllers\Settings\MasterData\GenderController;
use App\Http\Controllers\Settings\MasterData\ReligionController;
use App\Http\Controllers\Settings\MasterData\VisaTypeController;
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

        Route::get('visa-types', [VisaTypeController::class, 'index'])
            ->middleware('can:settings.master-data.visa-types.view')
            ->name('visa-types.index');
        Route::post('visa-types', [VisaTypeController::class, 'store'])
            ->middleware('can:settings.master-data.visa-types.create')
            ->name('visa-types.store');
        Route::put('visa-types/{visa_type}', [VisaTypeController::class, 'update'])
            ->middleware('can:settings.master-data.visa-types.update')
            ->name('visa-types.update');
        Route::delete('visa-types/{visa_type}', [VisaTypeController::class, 'destroy'])
            ->middleware('can:settings.master-data.visa-types.delete')
            ->name('visa-types.destroy');

        Route::get('religions', [ReligionController::class, 'index'])
            ->middleware('can:settings.master-data.religions.view')
            ->name('religions.index');
        Route::post('religions', [ReligionController::class, 'store'])
            ->middleware('can:settings.master-data.religions.create')
            ->name('religions.store');
        Route::put('religions/{religion}', [ReligionController::class, 'update'])
            ->middleware('can:settings.master-data.religions.update')
            ->name('religions.update');
        Route::delete('religions/{religion}', [ReligionController::class, 'destroy'])
            ->middleware('can:settings.master-data.religions.delete')
            ->name('religions.destroy');

        Route::get('genders', [GenderController::class, 'index'])
            ->middleware('can:settings.master-data.genders.view')
            ->name('genders.index');
        Route::post('genders', [GenderController::class, 'store'])
            ->middleware('can:settings.master-data.genders.create')
            ->name('genders.store');
        Route::put('genders/{gender}', [GenderController::class, 'update'])
            ->middleware('can:settings.master-data.genders.update')
            ->name('genders.update');
        Route::delete('genders/{gender}', [GenderController::class, 'destroy'])
            ->middleware('can:settings.master-data.genders.delete')
            ->name('genders.destroy');

        Route::get('banks', [BankController::class, 'index'])
            ->middleware('can:settings.master-data.banks.view')
            ->name('banks.index');
        Route::post('banks', [BankController::class, 'store'])
            ->middleware('can:settings.master-data.banks.create')
            ->name('banks.store');
        Route::put('banks/{bank}', [BankController::class, 'update'])
            ->middleware('can:settings.master-data.banks.update')
            ->name('banks.update');
        Route::delete('banks/{bank}', [BankController::class, 'destroy'])
            ->middleware('can:settings.master-data.banks.delete')
            ->name('banks.destroy');
    });
});
