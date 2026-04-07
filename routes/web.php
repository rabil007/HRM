<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Organization\CompanyController;

Route::get('/', fn () => redirect()->route('login'))->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
    Route::get('organization/companies', [CompanyController::class, 'index'])->name('organization.companies');
    Route::post('organization/companies', [CompanyController::class, 'store'])->name('organization.companies.store');
    Route::put('organization/companies/{company}', [CompanyController::class, 'update'])->name('organization.companies.update');
    Route::delete('organization/companies/{company}', [CompanyController::class, 'destroy'])->name('organization.companies.destroy');
});

require __DIR__.'/settings.php';
