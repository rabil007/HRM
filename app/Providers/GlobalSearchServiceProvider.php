<?php

namespace App\Providers;

use App\Http\Controllers\GlobalSearchController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class GlobalSearchServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'verified'])
            ->get('/search', GlobalSearchController::class)
            ->name('global-search');
    }
}
