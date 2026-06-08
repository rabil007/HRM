<?php

use App\Http\Controllers\Hikvision\HikvisionAccessEventController;
use App\Http\Controllers\Hikvision\HikvisionPersonController;
use App\Http\Controllers\Organization\ActivityLogController;
use App\Http\Controllers\Organization\BranchController;
use App\Http\Controllers\Organization\CompanyController;
use App\Http\Controllers\Organization\CompanySwitchController;
use App\Http\Controllers\Organization\DashboardController;
use App\Http\Controllers\Organization\DepartmentController;
use App\Http\Controllers\Organization\DocumentBulkEmailController;
use App\Http\Controllers\Organization\DocumentBulkFilesDeleteController;
use App\Http\Controllers\Organization\DocumentBulkFilesDownloadController;
use App\Http\Controllers\Organization\DocumentBulkFolderDownloadController;
use App\Http\Controllers\Organization\DocumentBulkPdfMergeController;
use App\Http\Controllers\Organization\DocumentBulkShareLinksController;
use App\Http\Controllers\Organization\DocumentBulkWhatsAppController;
use App\Http\Controllers\Organization\DocumentFileDownloadController;
use App\Http\Controllers\Organization\DocumentFolderDownloadController;
use App\Http\Controllers\Organization\DocumentsFolderIndexController;
use App\Http\Controllers\Organization\DocumentShareController;
use App\Http\Controllers\Organization\EmployeeBankAccountController;
use App\Http\Controllers\Organization\EmployeeContractController;
use App\Http\Controllers\Organization\EmployeeController;
use App\Http\Controllers\Organization\EmployeeCvPrintController;
use App\Http\Controllers\Organization\EmployeeDocumentController;
use App\Http\Controllers\Organization\EmployeeDocumentDownloadController;
use App\Http\Controllers\Organization\EmployeeDocumentsBrowseController;
use App\Http\Controllers\Organization\EmployeeEducationQualificationController;
use App\Http\Controllers\Organization\EmployeeExportController;
use App\Http\Controllers\Organization\EmployeeImportController;
use App\Http\Controllers\Organization\EmployeeLanguageController;
use App\Http\Controllers\Organization\EmployeeProfileTemplateController;
use App\Http\Controllers\Organization\EmployeeSeaServiceController;
use App\Http\Controllers\Organization\EmployeeTrainingController;
use App\Http\Controllers\Organization\EmployeeUserController;
use App\Http\Controllers\Organization\EmployeeVaccinationController;
use App\Http\Controllers\Organization\EmployeeWorkExperienceController;
use App\Http\Controllers\Organization\PositionController;
use App\Http\Controllers\Organization\RoleController;
use App\Http\Controllers\Organization\SendWhatsAppDocumentTemplateController;
use App\Http\Controllers\Organization\UserController;
use App\Http\Controllers\Webhooks\HikvisionWebhookController;
use App\Http\Controllers\Webhooks\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('login'))->name('home');

Route::get('organization/documents/share/{document}', DocumentShareController::class)
    ->middleware('signed')
    ->name('organization.documents.share');

Route::match(['get', 'post'], 'whatsapp/webhook', WhatsAppWebhookController::class)
    ->name('whatsapp.webhook');

Route::match(['get', 'post'], 'webhooks/whatsapp', WhatsAppWebhookController::class)
    ->name('webhooks.whatsapp');

Route::match(['get', 'post'], 'webhooks/hikvision', HikvisionWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('webhooks.hikvision');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
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
    Route::post('organization/employees/ensure', [EmployeeController::class, 'ensure'])->middleware('can:employees.create')->name('organization.employees.ensure');
    Route::get('organization/employees/export', [EmployeeExportController::class, 'export'])->middleware('can:employees.export')->name('organization.employees.export');
    Route::get('organization/employees/import', [EmployeeImportController::class, 'importPage'])->middleware('can:employees.import')->name('organization.employees.import.page');
    Route::get('organization/employees/import/template', [EmployeeImportController::class, 'importTemplate'])->middleware('can:employees.import')->name('organization.employees.import.template');
    Route::post('organization/employees/import/preview', [EmployeeImportController::class, 'importPreview'])->middleware('can:employees.import')->name('organization.employees.import.preview');
    Route::post('organization/employees/import', [EmployeeImportController::class, 'import'])->middleware('can:employees.import')->name('organization.employees.import');
    Route::get('organization/employees/{employee}/cv', EmployeeCvPrintController::class)->middleware('can:employees.view')->name('organization.employees.cv');
    Route::post('organization/employees/{employee}/user', [EmployeeUserController::class, 'store'])->middleware('can:users.create')->name('organization.employees.user.store');
    Route::get('organization/employees/{employee}', [EmployeeController::class, 'show'])->middleware('can:employees.view')->name('organization.employees.show');
    Route::post('organization/employees', [EmployeeController::class, 'store'])->middleware('can:employees.create')->name('organization.employees.store');
    Route::put('organization/employees/{employee}', [EmployeeController::class, 'update'])->middleware('can:employees.update')->name('organization.employees.update');
    Route::put('organization/employees/{employee}/status', [EmployeeController::class, 'updateStatus'])->middleware('can:employees.update')->name('organization.employees.status');
    Route::put('organization/employees/{employee}/profile-template', [EmployeeController::class, 'assignProfileTemplate'])->middleware('can:employees.update')->name('organization.employees.profile-template.assign');
    Route::delete('organization/employees/{employee}', [EmployeeController::class, 'destroy'])->middleware('can:employees.delete')->name('organization.employees.destroy');
    Route::middleware('can:documents.view')->group(function () {
        Route::get('organization/documents', DocumentsFolderIndexController::class)->name('organization.documents');
        Route::get('organization/documents/employees/{employee}', EmployeeDocumentsBrowseController::class)->name('organization.documents.employee');
        Route::post('organization/documents/employees/{employee}/files/email', DocumentBulkEmailController::class)->name('organization.documents.employee.files.email');
        Route::get('organization/employees/{employee}/documents/{document}/versions', [EmployeeDocumentController::class, 'versions'])->name('organization.employees.documents.versions');
    });
    Route::middleware('can:documents.share')->group(function () {
        Route::post('organization/documents/employees/{employee}/files/share-links', DocumentBulkShareLinksController::class)
            ->name('organization.documents.employee.files.share-links');
        Route::post('organization/documents/employees/{employee}/files/whatsapp', DocumentBulkWhatsAppController::class)
            ->name('organization.documents.employee.files.whatsapp');
        Route::post('organization/documents/employees/{employee}/files/{document}/whatsapp-template', SendWhatsAppDocumentTemplateController::class)
            ->name('organization.documents.employee.files.whatsapp-template');
    });
    Route::middleware('can:documents.download')->group(function () {
        Route::get('organization/documents/employees/{employee}/download', DocumentFolderDownloadController::class)->name('organization.documents.employee.download');
        Route::post('organization/documents/folders/bulk-download', DocumentBulkFolderDownloadController::class)->name('organization.documents.folders.bulk-download');
        Route::post('organization/documents/files/bulk-download', DocumentBulkFilesDownloadController::class)->name('organization.documents.files.bulk-download');
        Route::get('organization/documents/files/{document}/download', DocumentFileDownloadController::class)->name('organization.documents.files.download');
        Route::post('organization/documents/employees/{employee}/files/merge-pdf', DocumentBulkPdfMergeController::class)->name('organization.documents.employee.files.merge-pdf');
        Route::get('organization/employees/{employee}/documents/download', EmployeeDocumentDownloadController::class)->name('organization.employees.documents.download');
    });
    Route::delete('organization/documents/employees/{employee}/files/bulk', DocumentBulkFilesDeleteController::class)
        ->middleware('can:documents.delete')
        ->name('organization.documents.employee.files.bulk-destroy');
    Route::post('organization/employees/{employee}/documents', [EmployeeDocumentController::class, 'store'])->middleware('can:documents.upload')->name('organization.employees.documents.store');
    Route::post('organization/employees/{employee}/documents/bulk', [EmployeeDocumentController::class, 'bulkStore'])->middleware('can:documents.upload')->name('organization.employees.documents.bulk-store');
    Route::put('organization/employees/{employee}/documents/{document}', [EmployeeDocumentController::class, 'update'])->middleware('can:documents.upload')->name('organization.employees.documents.update');
    Route::post('organization/employees/{employee}/documents/{document}/replace', [EmployeeDocumentController::class, 'replace'])->middleware('can:documents.upload')->name('organization.employees.documents.replace');
    Route::delete('organization/employees/{employee}/documents/{document}', [EmployeeDocumentController::class, 'destroy'])->middleware('can:documents.delete')->name('organization.employees.documents.destroy');

    Route::post('organization/employees/{employee}/contracts', [EmployeeContractController::class, 'store'])->middleware('can:employees.contracts.manage')->name('organization.employees.contracts.store');
    Route::put('organization/employees/{employee}/contracts/{employeeContract}', [EmployeeContractController::class, 'update'])->middleware('can:employees.contracts.manage')->name('organization.employees.contracts.update');
    Route::delete('organization/employees/{employee}/contracts/{employeeContract}', [EmployeeContractController::class, 'destroy'])->middleware('can:employees.contracts.manage')->name('organization.employees.contracts.destroy');

    Route::post('organization/employees/{employee}/education', [EmployeeEducationQualificationController::class, 'store'])->middleware('can:employees.education.manage')->name('organization.employees.education.store');
    Route::put('organization/employees/{employee}/education/{qualification}', [EmployeeEducationQualificationController::class, 'update'])->middleware('can:employees.education.manage')->name('organization.employees.education.update');
    Route::delete('organization/employees/{employee}/education/{qualification}', [EmployeeEducationQualificationController::class, 'destroy'])->middleware('can:employees.education.manage')->name('organization.employees.education.destroy');

    Route::get('organization/employees/{employee}/work-experience/import/template', [EmployeeWorkExperienceController::class, 'importTemplate'])->middleware('can:employees.work_experience.manage')->name('organization.employees.work-experience.import.template');
    Route::post('organization/employees/{employee}/work-experience/import', [EmployeeWorkExperienceController::class, 'import'])->middleware('can:employees.work_experience.manage')->name('organization.employees.work-experience.import');
    Route::post('organization/employees/{employee}/work-experience', [EmployeeWorkExperienceController::class, 'store'])->middleware('can:employees.work_experience.manage')->name('organization.employees.work-experience.store');
    Route::put('organization/employees/{employee}/work-experience/{workExperience}', [EmployeeWorkExperienceController::class, 'update'])->middleware('can:employees.work_experience.manage')->name('organization.employees.work-experience.update');
    Route::delete('organization/employees/{employee}/work-experience/{workExperience}', [EmployeeWorkExperienceController::class, 'destroy'])->middleware('can:employees.work_experience.manage')->name('organization.employees.work-experience.destroy');

    Route::get('organization/employees/{employee}/vaccinations/import/template', [EmployeeVaccinationController::class, 'importTemplate'])->middleware('can:employees.vaccination.manage')->name('organization.employees.vaccinations.import.template');
    Route::post('organization/employees/{employee}/vaccinations/import', [EmployeeVaccinationController::class, 'import'])->middleware('can:employees.vaccination.manage')->name('organization.employees.vaccinations.import');
    Route::post('organization/employees/{employee}/vaccinations', [EmployeeVaccinationController::class, 'store'])->middleware('can:employees.vaccination.manage')->name('organization.employees.vaccinations.store');
    Route::put('organization/employees/{employee}/vaccinations/{vaccination}', [EmployeeVaccinationController::class, 'update'])->middleware('can:employees.vaccination.manage')->name('organization.employees.vaccinations.update');
    Route::delete('organization/employees/{employee}/vaccinations/{vaccination}', [EmployeeVaccinationController::class, 'destroy'])->middleware('can:employees.vaccination.manage')->name('organization.employees.vaccinations.destroy');

    Route::post('organization/employees/{employee}/languages', [EmployeeLanguageController::class, 'store'])->middleware('can:employees.languages.manage')->name('organization.employees.languages.store');
    Route::put('organization/employees/{employee}/languages/{language}', [EmployeeLanguageController::class, 'update'])->middleware('can:employees.languages.manage')->name('organization.employees.languages.update');
    Route::delete('organization/employees/{employee}/languages/{language}', [EmployeeLanguageController::class, 'destroy'])->middleware('can:employees.languages.manage')->name('organization.employees.languages.destroy');

    Route::get('organization/employees/{employee}/training/import/template', [EmployeeTrainingController::class, 'importTemplate'])->middleware('can:employees.training.manage')->name('organization.employees.training.import.template');
    Route::post('organization/employees/{employee}/training/import', [EmployeeTrainingController::class, 'import'])->middleware('can:employees.training.manage')->name('organization.employees.training.import');
    Route::post('organization/employees/{employee}/training', [EmployeeTrainingController::class, 'store'])->middleware('can:employees.training.manage')->name('organization.employees.training.store');
    Route::put('organization/employees/{employee}/training/{training}', [EmployeeTrainingController::class, 'update'])->middleware('can:employees.training.manage')->name('organization.employees.training.update');
    Route::delete('organization/employees/{employee}/training/{training}', [EmployeeTrainingController::class, 'destroy'])->middleware('can:employees.training.manage')->name('organization.employees.training.destroy');

    Route::post('organization/employees/{employee}/bank-accounts', [EmployeeBankAccountController::class, 'store'])->middleware('can:employees.bank_accounts.manage')->name('organization.employees.bank-accounts.store');
    Route::put('organization/employees/{employee}/bank-accounts/{bankAccount}', [EmployeeBankAccountController::class, 'update'])->middleware('can:employees.bank_accounts.manage')->name('organization.employees.bank-accounts.update');
    Route::delete('organization/employees/{employee}/bank-accounts/{bankAccount}', [EmployeeBankAccountController::class, 'destroy'])->middleware('can:employees.bank_accounts.manage')->name('organization.employees.bank-accounts.destroy');

    Route::get('organization/employees/{employee}/sea-services/import/template', [EmployeeSeaServiceController::class, 'importTemplate'])->middleware('can:employees.sea_service.manage')->name('organization.employees.sea-services.import.template');
    Route::post('organization/employees/{employee}/sea-services/import', [EmployeeSeaServiceController::class, 'import'])->middleware('can:employees.sea_service.manage')->name('organization.employees.sea-services.import');
    Route::post('organization/employees/{employee}/sea-services/reorder', [EmployeeSeaServiceController::class, 'reorder'])->middleware('can:employees.sea_service.manage')->name('organization.employees.sea-services.reorder');
    Route::post('organization/employees/{employee}/sea-services', [EmployeeSeaServiceController::class, 'store'])->middleware('can:employees.sea_service.manage')->name('organization.employees.sea-services.store');
    Route::put('organization/employees/{employee}/sea-services/{seaService}', [EmployeeSeaServiceController::class, 'update'])->middleware('can:employees.sea_service.manage')->name('organization.employees.sea-services.update');
    Route::delete('organization/employees/{employee}/sea-services/bulk', [EmployeeSeaServiceController::class, 'bulkDestroy'])->middleware('can:employees.sea_service.manage')->name('organization.employees.sea-services.bulk-destroy');
    Route::delete('organization/employees/{employee}/sea-services/{seaService}', [EmployeeSeaServiceController::class, 'destroy'])->middleware('can:employees.sea_service.manage')->name('organization.employees.sea-services.destroy');

    Route::get('organization/activity-logs', [ActivityLogController::class, 'index'])
        ->middleware('can:audit.view')
        ->name('organization.activity-logs');

    Route::get('organization/templates/employee-profile', [EmployeeProfileTemplateController::class, 'index'])
        ->middleware('can:employee_profile_templates.view')
        ->name('organization.employee-profile-templates.index');
    Route::get('organization/templates/employee-profile/create', [EmployeeProfileTemplateController::class, 'create'])
        ->middleware('can:employee_profile_templates.create')
        ->name('organization.employee-profile-templates.create');
    Route::post('organization/templates/employee-profile', [EmployeeProfileTemplateController::class, 'store'])
        ->middleware('can:employee_profile_templates.create')
        ->name('organization.employee-profile-templates.store');
    Route::get('organization/templates/employee-profile/{employeeProfileTemplate}/edit', [EmployeeProfileTemplateController::class, 'edit'])
        ->middleware('can:employee_profile_templates.update')
        ->name('organization.employee-profile-templates.edit');
    Route::put('organization/templates/employee-profile/{employeeProfileTemplate}', [EmployeeProfileTemplateController::class, 'update'])
        ->middleware('can:employee_profile_templates.update')
        ->name('organization.employee-profile-templates.update');
    Route::delete('organization/templates/employee-profile/{employeeProfileTemplate}', [EmployeeProfileTemplateController::class, 'destroy'])
        ->middleware('can:employee_profile_templates.delete')
        ->name('organization.employee-profile-templates.destroy');

    Route::get('hikvision/persons', [HikvisionPersonController::class, 'index'])
        ->middleware('can:hikvision.persons.view')
        ->name('hikvision.persons.index');

    Route::post('hikvision/persons/sync', [HikvisionPersonController::class, 'sync'])
        ->middleware('can:hikvision.persons.sync')
        ->name('hikvision.persons.sync');

    Route::post('hikvision/persons', [HikvisionPersonController::class, 'store'])
        ->middleware('can:hikvision.persons.create')
        ->name('hikvision.persons.store');

    Route::put('hikvision/persons/{person}', [HikvisionPersonController::class, 'update'])
        ->middleware('can:hikvision.persons.update')
        ->name('hikvision.persons.update');

    Route::delete('hikvision/persons/{person}', [HikvisionPersonController::class, 'destroy'])
        ->middleware('can:hikvision.persons.delete')
        ->name('hikvision.persons.destroy');

    Route::post('hikvision/persons/{person}/photo', [HikvisionPersonController::class, 'uploadPhoto'])
        ->middleware('can:hikvision.persons.update')
        ->name('hikvision.persons.photo');

    Route::put('hikvision/persons/{person}/employee', [HikvisionPersonController::class, 'linkEmployee'])
        ->middleware('can:hikvision.persons.link')
        ->name('hikvision.persons.employee.link');

    Route::redirect('hikvision/devices', '/settings/application?tab=hikvision');

    Route::get('hikvision/access-events', [HikvisionAccessEventController::class, 'index'])
        ->middleware('can:hikvision.events.view')
        ->name('hikvision.access-events.index');

    Route::post('hikvision/access-events/fetch', [HikvisionAccessEventController::class, 'fetch'])
        ->middleware('can:hikvision.events.fetch')
        ->name('hikvision.access-events.fetch');
});

require __DIR__.'/settings.php';
