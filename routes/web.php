<?php

use App\Http\Controllers\Organization\BranchController;
use App\Http\Controllers\Organization\CompanyController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('login'))->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
    Route::get('organization/companies', [CompanyController::class, 'index'])->name('organization.companies');
    Route::get('organization/companies/export', [CompanyController::class, 'export'])->name('organization.companies.export');
    Route::get('organization/companies/{company}', [CompanyController::class, 'show'])->name('organization.companies.show');
    Route::post('organization/companies', [CompanyController::class, 'store'])->name('organization.companies.store');
    Route::put('organization/companies/{company}', [CompanyController::class, 'update'])->name('organization.companies.update');
    Route::delete('organization/companies/{company}', [CompanyController::class, 'destroy'])->name('organization.companies.destroy');

    Route::get('organization/branches', [BranchController::class, 'index'])->name('organization.branches');
    Route::get('organization/branches/export', [BranchController::class, 'export'])->name('organization.branches.export');
    Route::get('organization/branches/{branch}', [BranchController::class, 'show'])->name('organization.branches.show');
    Route::post('organization/branches', [BranchController::class, 'store'])->name('organization.branches.store');
    Route::put('organization/branches/{branch}', [BranchController::class, 'update'])->name('organization.branches.update');
    Route::delete('organization/branches/{branch}', [BranchController::class, 'destroy'])->name('organization.branches.destroy');
});

require __DIR__.'/settings.php';
