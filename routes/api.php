<?php

use App\Http\Controllers\Organization\Api\DocumentEmployeeFilesApiController;
use App\Http\Controllers\Organization\Api\DocumentEmployeesApiController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified'])->group(function () {
    Route::get('documents/employees', DocumentEmployeesApiController::class)
        ->middleware('can:employees.view')
        ->name('api.documents.employees');

    Route::get('documents/employees/{employee}', DocumentEmployeeFilesApiController::class)
        ->middleware('can:employees.view')
        ->name('api.documents.employees.show');
});
