<?php

use App\Http\Controllers\Organization\BranchController;
use App\Http\Controllers\Organization\CompanyController;
use App\Http\Controllers\Organization\DepartmentController;
use App\Http\Controllers\Organization\PositionController;
use App\Http\Controllers\Organization\RoleController;
use App\Http\Controllers\Organization\UserController;
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

    Route::get('organization/positions', [PositionController::class, 'index'])->name('organization.positions');
    Route::get('organization/positions/export', [PositionController::class, 'export'])->name('organization.positions.export');
    Route::get('organization/positions/{position}', [PositionController::class, 'show'])->name('organization.positions.show');
    Route::post('organization/positions', [PositionController::class, 'store'])->name('organization.positions.store');
    Route::put('organization/positions/{position}', [PositionController::class, 'update'])->name('organization.positions.update');
    Route::delete('organization/positions/{position}', [PositionController::class, 'destroy'])->name('organization.positions.destroy');

    Route::get('organization/roles', [RoleController::class, 'index'])->name('organization.roles');
    Route::get('organization/roles/export', [RoleController::class, 'export'])->name('organization.roles.export');
    Route::get('organization/roles/{role}', [RoleController::class, 'show'])->name('organization.roles.show');
    Route::post('organization/roles', [RoleController::class, 'store'])->name('organization.roles.store');
    Route::put('organization/roles/{role}', [RoleController::class, 'update'])->name('organization.roles.update');
    Route::delete('organization/roles/{role}', [RoleController::class, 'destroy'])->name('organization.roles.destroy');

    Route::get('organization/users', [UserController::class, 'index'])->name('organization.users');
    Route::get('organization/users/export', [UserController::class, 'export'])->name('organization.users.export');
    Route::get('organization/users/{user}', [UserController::class, 'show'])->name('organization.users.show');
    Route::post('organization/users', [UserController::class, 'store'])->name('organization.users.store');
    Route::put('organization/users/{user}', [UserController::class, 'update'])->name('organization.users.update');
    Route::delete('organization/users/{user}', [UserController::class, 'destroy'])->name('organization.users.destroy');
});

require __DIR__.'/settings.php';
