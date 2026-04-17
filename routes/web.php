<?php

use App\Http\Controllers\Onboarding\OnboardingTemplateController;
use App\Http\Controllers\Organization\ActivityLogController;
use App\Http\Controllers\Organization\BranchController;
use App\Http\Controllers\Organization\CompanyController;
use App\Http\Controllers\Organization\CompanySwitchController;
use App\Http\Controllers\Organization\DepartmentController;
use App\Http\Controllers\Organization\EmployeeController;
use App\Http\Controllers\Organization\PositionController;
use App\Http\Controllers\Organization\RoleController;
use App\Http\Controllers\Organization\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('login'))->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
    Route::get('organization/companies', [CompanyController::class, 'index'])->middleware('can:companies.view')->name('organization.companies');
    Route::get('organization/companies/export', [CompanyController::class, 'export'])->middleware('can:companies.export')->name('organization.companies.export');
    Route::get('organization/companies/{company}', [CompanyController::class, 'show'])->middleware('can:companies.view')->name('organization.companies.show');
    Route::post('organization/companies', [CompanyController::class, 'store'])->middleware('can:companies.create')->name('organization.companies.store');
    Route::put('organization/companies/{company}', [CompanyController::class, 'update'])->middleware('can:companies.update')->name('organization.companies.update');
    Route::put('organization/companies/{company}/status', [CompanyController::class, 'updateStatus'])->middleware('can:companies.update')->name('organization.companies.status');
    Route::delete('organization/companies/{company}', [CompanyController::class, 'destroy'])->middleware('can:companies.delete')->name('organization.companies.destroy');
    Route::post('organization/companies/switch', CompanySwitchController::class)->name('organization.companies.switch');

    Route::get('organization/branches', [BranchController::class, 'index'])->middleware('can:branches.view')->name('organization.branches');
    Route::get('organization/branches/export', [BranchController::class, 'export'])->middleware('can:branches.export')->name('organization.branches.export');
    Route::get('organization/branches/{branch}', [BranchController::class, 'show'])->middleware('can:branches.view')->name('organization.branches.show');
    Route::post('organization/branches', [BranchController::class, 'store'])->middleware('can:branches.create')->name('organization.branches.store');
    Route::put('organization/branches/{branch}', [BranchController::class, 'update'])->middleware('can:branches.update')->name('organization.branches.update');
    Route::put('organization/branches/{branch}/status', [BranchController::class, 'updateStatus'])->middleware('can:branches.update')->name('organization.branches.status');
    Route::delete('organization/branches/{branch}', [BranchController::class, 'destroy'])->middleware('can:branches.delete')->name('organization.branches.destroy');

    Route::get('organization/departments', [DepartmentController::class, 'index'])->middleware('can:departments.view')->name('organization.departments');
    Route::get('organization/departments/export', [DepartmentController::class, 'export'])->middleware('can:departments.export')->name('organization.departments.export');
    Route::get('organization/departments/{department}', [DepartmentController::class, 'show'])->middleware('can:departments.view')->name('organization.departments.show');
    Route::post('organization/departments', [DepartmentController::class, 'store'])->middleware('can:departments.create')->name('organization.departments.store');
    Route::put('organization/departments/{department}', [DepartmentController::class, 'update'])->middleware('can:departments.update')->name('organization.departments.update');
    Route::put('organization/departments/{department}/status', [DepartmentController::class, 'updateStatus'])->middleware('can:departments.update')->name('organization.departments.status');
    Route::delete('organization/departments/{department}', [DepartmentController::class, 'destroy'])->middleware('can:departments.delete')->name('organization.departments.destroy');

    Route::get('organization/positions', [PositionController::class, 'index'])->middleware('can:positions.view')->name('organization.positions');
    Route::get('organization/positions/export', [PositionController::class, 'export'])->middleware('can:positions.export')->name('organization.positions.export');
    Route::get('organization/positions/{position}', [PositionController::class, 'show'])->middleware('can:positions.view')->name('organization.positions.show');
    Route::post('organization/positions', [PositionController::class, 'store'])->middleware('can:positions.create')->name('organization.positions.store');
    Route::put('organization/positions/{position}', [PositionController::class, 'update'])->middleware('can:positions.update')->name('organization.positions.update');
    Route::put('organization/positions/{position}/status', [PositionController::class, 'updateStatus'])->middleware('can:positions.update')->name('organization.positions.status');
    Route::delete('organization/positions/{position}', [PositionController::class, 'destroy'])->middleware('can:positions.delete')->name('organization.positions.destroy');

    Route::get('organization/roles', [RoleController::class, 'index'])->middleware('can:roles.view')->name('organization.roles');
    Route::get('organization/roles/export', [RoleController::class, 'export'])->middleware('can:roles.export')->name('organization.roles.export');
    Route::get('organization/roles/{role}', [RoleController::class, 'show'])->middleware('can:roles.view')->name('organization.roles.show');
    Route::post('organization/roles', [RoleController::class, 'store'])->middleware('can:roles.create')->name('organization.roles.store');
    Route::put('organization/roles/{role}', [RoleController::class, 'update'])->middleware('can:roles.update')->name('organization.roles.update');
    Route::delete('organization/roles/{role}', [RoleController::class, 'destroy'])->middleware('can:roles.delete')->name('organization.roles.destroy');

    Route::get('organization/users', [UserController::class, 'index'])->middleware('can:users.view')->name('organization.users');
    Route::get('organization/users/export', [UserController::class, 'export'])->middleware('can:users.export')->name('organization.users.export');
    Route::get('organization/users/{user}', [UserController::class, 'show'])->middleware('can:users.view')->name('organization.users.show');
    Route::post('organization/users', [UserController::class, 'store'])->middleware('can:users.create')->name('organization.users.store');
    Route::put('organization/users/{user}', [UserController::class, 'update'])->middleware('can:users.update')->name('organization.users.update');
    Route::put('organization/users/{user}/status', [UserController::class, 'updateStatus'])->middleware('can:users.update')->name('organization.users.status');
    Route::delete('organization/users/{user}', [UserController::class, 'destroy'])->middleware('can:users.delete')->name('organization.users.destroy');
    Route::post('organization/users/{user}/memberships', [UserController::class, 'storeMembership'])->middleware('can:users.update')->name('organization.users.memberships.store');
    Route::put('organization/users/{user}/memberships/{company}', [UserController::class, 'updateMembership'])->middleware('can:users.update')->name('organization.users.memberships.update');
    Route::delete('organization/users/{user}/memberships/{company}', [UserController::class, 'destroyMembership'])->middleware('can:users.update')->name('organization.users.memberships.destroy');

    Route::get('organization/employees', [EmployeeController::class, 'index'])->middleware('can:employees.view')->name('organization.employees');
    Route::get('organization/employees/create', [EmployeeController::class, 'create'])->middleware('can:employees.create')->name('organization.employees.create');
    Route::get('organization/employees/export', [EmployeeController::class, 'export'])->middleware('can:employees.export')->name('organization.employees.export');
    Route::get('organization/employees/{employee}', [EmployeeController::class, 'show'])->middleware('can:employees.view')->name('organization.employees.show');
    Route::post('organization/employees', [EmployeeController::class, 'store'])->middleware('can:employees.create')->name('organization.employees.store');
    Route::put('organization/employees/{employee}', [EmployeeController::class, 'update'])->middleware('can:employees.update')->name('organization.employees.update');
    Route::put('organization/employees/{employee}/status', [EmployeeController::class, 'updateStatus'])->middleware('can:employees.update')->name('organization.employees.status');
    Route::delete('organization/employees/{employee}', [EmployeeController::class, 'destroy'])->middleware('can:employees.delete')->name('organization.employees.destroy');

    Route::get('organization/activity-logs', [ActivityLogController::class, 'index'])
        ->middleware('can:audit.view')
        ->name('organization.activity-logs');

    Route::prefix('onboarding')->name('onboarding.')->group(function () {
        Route::get('templates', [OnboardingTemplateController::class, 'index'])
            ->middleware('can:onboarding.templates.view')
            ->name('templates.index');
        Route::get('templates/create', [OnboardingTemplateController::class, 'create'])
            ->middleware('can:onboarding.templates.create')
            ->name('templates.create');
        Route::get('templates/{template}/edit', [OnboardingTemplateController::class, 'edit'])
            ->middleware('can:onboarding.templates.update')
            ->name('templates.edit');
        Route::post('templates', [OnboardingTemplateController::class, 'store'])
            ->middleware('can:onboarding.templates.create')
            ->name('templates.store');
        Route::put('templates/{template}', [OnboardingTemplateController::class, 'update'])
            ->middleware('can:onboarding.templates.update')
            ->name('templates.update');
        Route::delete('templates/{template}', [OnboardingTemplateController::class, 'destroy'])
            ->middleware('can:onboarding.templates.delete')
            ->name('templates.destroy');

    });
});

require __DIR__.'/settings.php';
