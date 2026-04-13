<?php

use App\Http\Controllers\Organization\BranchController;
use App\Http\Controllers\Organization\CompanyController;
use App\Http\Controllers\Organization\DepartmentController;
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

    Route::get('organization/departments', [DepartmentController::class, 'index'])->name('organization.departments');
    Route::get('organization/departments/export', [DepartmentController::class, 'export'])->name('organization.departments.export');
    Route::get('organization/departments/{department}', [DepartmentController::class, 'show'])->name('organization.departments.show');
    Route::post('organization/departments', [DepartmentController::class, 'store'])->name('organization.departments.store');
    Route::put('organization/departments/{department}', [DepartmentController::class, 'update'])->name('organization.departments.update');
    Route::delete('organization/departments/{department}', [DepartmentController::class, 'destroy'])->name('organization.departments.destroy');
});

require __DIR__.'/settings.php';
