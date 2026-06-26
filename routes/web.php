<?php

use App\Http\Controllers\ApplicationLogController;
use App\Http\Controllers\Attendance\AttendanceCalendarController;
use App\Http\Controllers\Attendance\AttendanceRecordController;
use App\Http\Controllers\Attendance\LeaveRequestAttachmentController;
use App\Http\Controllers\Attendance\LeaveRequestController;
use App\Http\Controllers\Attendance\LeaveTypeController;
use App\Http\Controllers\DatabaseViewerController;
use App\Http\Controllers\Hikvision\HikvisionAccessEventController;
use App\Http\Controllers\Hikvision\HikvisionPersonController;
use App\Http\Controllers\JobRunController;
use App\Http\Controllers\Organization\ActivityLogController;
use App\Http\Controllers\Organization\BranchController;
use App\Http\Controllers\Organization\CompanyController;
use App\Http\Controllers\Organization\CompanySwitchController;
use App\Http\Controllers\Organization\CrewDeploymentController;
use App\Http\Controllers\Organization\CrewOperationsDashboardController;
use App\Http\Controllers\Organization\CrewOperationsSettingsController;
use App\Http\Controllers\Organization\CrewPlanningAssignmentController;
use App\Http\Controllers\Organization\CrewPlanningController;
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
use App\Http\Controllers\Organization\EmployeeDocumentShowController;
use App\Http\Controllers\Organization\EmployeeEducationQualificationController;
use App\Http\Controllers\Organization\EmployeeExportController;
use App\Http\Controllers\Organization\EmployeeImportController;
use App\Http\Controllers\Organization\EmployeeLanguageController;
use App\Http\Controllers\Organization\EmployeeOffshoreCvPrintController;
use App\Http\Controllers\Organization\EmployeeProfileTemplateController;
use App\Http\Controllers\Organization\EmployeeSalaryCertificatePrintController;
use App\Http\Controllers\Organization\EmployeeSeaServiceController;
use App\Http\Controllers\Organization\EmployeeTrainingController;
use App\Http\Controllers\Organization\EmployeeUserController;
use App\Http\Controllers\Organization\EmployeeVaccinationController;
use App\Http\Controllers\Organization\EmployeeWorkExperienceController;
use App\Http\Controllers\Organization\PositionController;
use App\Http\Controllers\Organization\RoleController;
use App\Http\Controllers\Organization\SendWhatsAppDocumentTemplateController;
use App\Http\Controllers\Organization\UserController;
use App\Http\Controllers\Organization\VesselManningController;
use App\Http\Controllers\Payroll\PayrollController;
use App\Http\Controllers\Payroll\PayrollOverviewController;
use App\Http\Controllers\Payroll\PayrollRecordController;
use App\Http\Controllers\Payroll\PayslipController;
use App\Http\Controllers\Payroll\WpsExportController;
use App\Http\Controllers\Webhooks\HikvisionWebhookController;
use App\Http\Controllers\Webhooks\WhatsAppWebhookController;
use App\Models\PayrollPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('login'))->name('home');

Route::match(['get', 'post'], 'organization/documents/share/{document}', DocumentShareController::class)
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
    Route::get('log', [ApplicationLogController::class, 'index'])->name('log');
    Route::get('log/export', [ApplicationLogController::class, 'export'])->name('log.export');
    Route::delete('log', [ApplicationLogController::class, 'destroy'])->name('log.clear');

    Route::get('jobs', [JobRunController::class, 'index'])->name('jobs.index');
    Route::post('jobs/failed/retry-all', [JobRunController::class, 'retryAllFailed'])->name('jobs.failed.retry-all');
    Route::delete('jobs/failed/clear-all', [JobRunController::class, 'destroyAllFailed'])->name('jobs.failed.destroy-all');
    Route::post('jobs/failed/{uuid}/retry', [JobRunController::class, 'retryFailed'])->name('jobs.failed.retry');
    Route::delete('jobs/failed/{uuid}', [JobRunController::class, 'destroyFailed'])->name('jobs.failed.destroy');

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

    Route::get('organization/crew-operations', CrewOperationsDashboardController::class)->name('organization.crew-operations.index');

    Route::get('organization/crew-deployments', [CrewDeploymentController::class, 'index'])->middleware('can:crew_operations.deployments.view')->name('organization.crew-deployments.index');
    Route::post('organization/crew-deployments', [CrewDeploymentController::class, 'store'])->middleware('can:crew_operations.deployments.create')->name('organization.crew-deployments.store');
    Route::get('organization/crew-deployments/export', [CrewDeploymentController::class, 'export'])->middleware('can:crew_operations.deployments.export')->name('organization.crew-deployments.export');
    Route::get('organization/crew-deployments/{deployment}', [CrewDeploymentController::class, 'show'])->middleware('can:crew_operations.deployments.view')->name('organization.crew-deployments.show');
    Route::put('organization/crew-deployments/{deployment}', [CrewDeploymentController::class, 'update'])->middleware('can:crew_operations.deployments.update')->name('organization.crew-deployments.update');
    Route::delete('organization/crew-deployments/{deployment}', [CrewDeploymentController::class, 'destroy'])->middleware('can:crew_operations.deployments.delete')->name('organization.crew-deployments.destroy');

    Route::get('organization/vessel-manning', [VesselManningController::class, 'index'])->middleware('can:crew_operations.vessel_manning.view')->name('organization.vessel-manning.index');
    Route::get('organization/vessel-manning/{vessel}', [VesselManningController::class, 'show'])->middleware('can:crew_operations.vessel_manning.view')->name('organization.vessel-manning.show');
    Route::put('organization/vessel-manning/{vessel}', [VesselManningController::class, 'update'])->name('organization.vessel-manning.update');

    Route::get('organization/crew-planning', [CrewPlanningController::class, 'index'])->middleware('can:crew_operations.planning.view')->name('organization.crew-planning.index');
    Route::post('organization/crew-planning/assignments', [CrewPlanningAssignmentController::class, 'store'])->middleware('can:crew_operations.planning.create')->name('organization.crew-planning.assignments.store');
    Route::put('organization/crew-planning/assignments/{assignment}', [CrewPlanningAssignmentController::class, 'update'])->middleware('can:crew_operations.planning.update')->name('organization.crew-planning.assignments.update');
    Route::delete('organization/crew-planning/assignments/{assignment}', [CrewPlanningAssignmentController::class, 'destroy'])->middleware('can:crew_operations.planning.delete')->name('organization.crew-planning.assignments.destroy');

    Route::get('organization/crew-operations/settings', [CrewOperationsSettingsController::class, 'index'])->middleware('can:crew_operations.planning.view')->name('organization.crew-operations.settings.index');
    Route::put('organization/crew-operations/settings', [CrewOperationsSettingsController::class, 'update'])->middleware('can:crew_operations.planning.update')->name('organization.crew-operations.settings.update');

    Route::get('payroll/overview', PayrollOverviewController::class)->name('payroll.overview');
    Route::get('payroll', [PayrollController::class, 'index'])->name('payroll.index');
    Route::get('payroll/records', [PayrollRecordController::class, 'index'])->middleware('can:payroll.records.view')->name('payroll.records.index');
    Route::get('payroll/payslips', [PayslipController::class, 'index'])->middleware('can:payroll.payslips.view')->name('payroll.payslips.index');
    Route::get('payroll/payslips/{payrollRecord}', [PayslipController::class, 'show'])->middleware('can:payroll.payslips.view')->name('payroll.payslips.show');
    Route::get('payroll/payslips/{payrollRecord}/download', [PayslipController::class, 'download'])->middleware('can:payroll.payslips.view')->name('payroll.payslips.download');
    Route::post('payroll/payslips/generate', [PayslipController::class, 'generate'])->name('payroll.payslips.generate');
    Route::post('payroll/payslips/email', [PayslipController::class, 'email'])->name('payroll.payslips.email');
    Route::get('payroll/wps', [WpsExportController::class, 'index'])->middleware('can:payroll.wps.view')->name('payroll.wps.index');
    Route::post('payroll/wps/export', [WpsExportController::class, 'export'])->name('payroll.wps.export');
    Route::post('payroll/periods', [PayrollController::class, 'storePeriod'])->middleware('can:payroll.periods.create')->name('payroll.periods.store');
    Route::get('payroll/{payrollPeriod}', [PayrollController::class, 'show'])->name('payroll.show');
    Route::post('payroll/{payrollPeriod}/timesheets', [PayrollController::class, 'storeTimesheet'])->name('payroll.timesheets.store');
    Route::get('payroll/{payrollPeriod}/timesheets/import/template', [PayrollController::class, 'importTemplate'])->name('payroll.timesheets.import.template');
    Route::post('payroll/{payrollPeriod}/timesheets/import/preview', [PayrollController::class, 'importPreview'])->name('payroll.timesheets.import.preview');
    Route::post('payroll/{payrollPeriod}/timesheets/import', [PayrollController::class, 'importTimesheets'])->name('payroll.timesheets.import');
    Route::post('payroll/{payrollPeriod}/generate', [PayrollController::class, 'generatePayroll'])->middleware('can:payroll.periods.update')->name('payroll.generate');
    Route::post('payroll/{payrollPeriod}/revert-to-draft', [PayrollController::class, 'revertToDraft'])->middleware('can:payroll.periods.revert_to_draft')->name('payroll.revert-to-draft');
    Route::post('payroll/{payrollPeriod}/approve', [PayrollController::class, 'approve'])->middleware('can:payroll.periods.approve')->name('payroll.approve');
    Route::post('payroll/{payrollPeriod}/mark-paid', [PayrollController::class, 'markPaid'])->middleware('can:payroll.periods.mark_paid')->name('payroll.mark-paid');
    Route::post('payroll/{payrollPeriod}/cancel', [PayrollController::class, 'cancel'])->middleware('can:payroll.periods.cancel')->name('payroll.cancel');

    Route::get('organization/payroll', fn () => redirect()->route('payroll.index'))->name('organization.payroll.index');
    Route::get('organization/payroll/{payrollPeriod}', fn (PayrollPeriod $payrollPeriod) => redirect()->route('payroll.show', $payrollPeriod))->name('organization.payroll.show');
    Route::get('organization/payroll-periods', fn () => redirect()->route('payroll.index'))->name('organization.payroll-periods.index');
    Route::get('organization/crew-payroll', function (Request $request) {
        $periodId = $request->integer('period_id');

        if ($periodId > 0) {
            return redirect()->route('payroll.show', $periodId);
        }

        return redirect()->route('payroll.index');
    })->name('organization.crew-payroll.index');
    Route::post('organization/crew-payroll/timesheets', fn () => abort(410, 'Use payroll.timesheets.store'))->name('organization.crew-payroll.timesheets.store');

    Route::get('organization/employees', [EmployeeController::class, 'index'])->middleware('can:employees.view')->name('organization.employees');
    Route::get('organization/employees/create', [EmployeeController::class, 'create'])->middleware('can:employees.create')->name('organization.employees.create');
    Route::post('organization/employees/ensure', [EmployeeController::class, 'ensure'])->middleware('can:employees.create')->name('organization.employees.ensure');
    Route::get('organization/employees/export', [EmployeeExportController::class, 'export'])->middleware('can:employees.export')->name('organization.employees.export');
    Route::get('organization/employees/import', [EmployeeImportController::class, 'importPage'])->middleware('can:employees.import')->name('organization.employees.import.page');
    Route::get('organization/employees/import/template', [EmployeeImportController::class, 'importTemplate'])->middleware('can:employees.import')->name('organization.employees.import.template');
    Route::post('organization/employees/import/preview', [EmployeeImportController::class, 'importPreview'])->middleware('can:employees.import')->name('organization.employees.import.preview');
    Route::post('organization/employees/import', [EmployeeImportController::class, 'import'])->middleware('can:employees.import')->name('organization.employees.import');
    Route::get('organization/employees/{employee}/cv', EmployeeCvPrintController::class)->middleware('can:employees.view')->name('organization.employees.cv');
    Route::get('organization/employees/{employee}/offshore-cv', EmployeeOffshoreCvPrintController::class)->middleware('can:employees.view')->name('organization.employees.offshore-cv');
    Route::get('organization/employees/{employee}/salary-certificate', EmployeeSalaryCertificatePrintController::class)->middleware('can:employees.view')->name('organization.employees.salary-certificate');
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
        Route::get('organization/documents/employees/{employee}/files/{document}', EmployeeDocumentShowController::class)->name('organization.documents.employee.files.show');
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
    Route::post('organization/employees/{employee}/training/bulk', [EmployeeTrainingController::class, 'bulkStore'])->middleware('can:employees.training.manage')->name('organization.employees.training.bulk-store');
    Route::post('organization/employees/{employee}/training', [EmployeeTrainingController::class, 'store'])->middleware('can:employees.training.manage')->name('organization.employees.training.store');
    Route::put('organization/employees/{employee}/training/{training}', [EmployeeTrainingController::class, 'update'])->middleware('can:employees.training.manage')->name('organization.employees.training.update');
    Route::delete('organization/employees/{employee}/training/bulk', [EmployeeTrainingController::class, 'bulkDestroy'])->middleware('can:employees.training.manage')->name('organization.employees.training.bulk-destroy');
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

    Route::get('attendance/records', [AttendanceRecordController::class, 'index'])
        ->middleware('can:attendance.records.view')
        ->name('attendance.records.index');

    Route::get('attendance/records/export', [AttendanceRecordController::class, 'export'])
        ->middleware('can:attendance.records.manage')
        ->name('attendance.records.export');

    Route::post('attendance/records', [AttendanceRecordController::class, 'store'])
        ->middleware('can:attendance.records.create')
        ->name('attendance.records.store');

    Route::put('attendance/records/{attendance_record}', [AttendanceRecordController::class, 'update'])
        ->middleware('can:attendance.records.update')
        ->name('attendance.records.update');

    Route::delete('attendance/records/{attendance_record}', [AttendanceRecordController::class, 'destroy'])
        ->middleware('can:attendance.records.delete')
        ->name('attendance.records.destroy');

    Route::get('attendance/calendar', [AttendanceCalendarController::class, 'index'])
        ->middleware('can:attendance.leave-requests.view')
        ->name('attendance.calendar.index');

    Route::get('attendance/types', [LeaveTypeController::class, 'index'])
        ->middleware('can:attendance.types.view')
        ->name('attendance.types.index');

    Route::get('attendance/types/{leave_type}', [LeaveTypeController::class, 'show'])
        ->middleware('can:attendance.types.view')
        ->name('attendance.types.show');

    Route::post('attendance/types', [LeaveTypeController::class, 'store'])
        ->middleware('can:attendance.types.create')
        ->name('attendance.types.store');

    Route::put('attendance/types/{leave_type}', [LeaveTypeController::class, 'update'])
        ->middleware('can:attendance.types.update')
        ->name('attendance.types.update');

    Route::put('attendance/types/{leave_type}/status', [LeaveTypeController::class, 'updateStatus'])
        ->middleware('can:attendance.types.update')
        ->name('attendance.types.status');

    Route::delete('attendance/types/{leave_type}', [LeaveTypeController::class, 'destroy'])
        ->middleware('can:attendance.types.delete')
        ->name('attendance.types.destroy');

    Route::get('attendance/leave-requests', [LeaveRequestController::class, 'index'])
        ->middleware('can:attendance.leave-requests.view')
        ->name('attendance.leave-requests.index');

    Route::get('attendance/leave-requests/{leave_request}', [LeaveRequestController::class, 'show'])
        ->middleware('can:attendance.leave-requests.view')
        ->name('attendance.leave-requests.show');

    Route::post('attendance/leave-requests', [LeaveRequestController::class, 'store'])
        ->middleware('can:attendance.leave-requests.create')
        ->name('attendance.leave-requests.store');

    Route::put('attendance/leave-requests/{leave_request}', [LeaveRequestController::class, 'update'])
        ->middleware('can:attendance.leave-requests.update')
        ->name('attendance.leave-requests.update');

    Route::delete('attendance/leave-requests/{leave_request}', [LeaveRequestController::class, 'destroy'])
        ->middleware('can:attendance.leave-requests.delete')
        ->name('attendance.leave-requests.destroy');

    Route::put('attendance/leave-requests/{leave_request}/approve', [LeaveRequestController::class, 'approve'])
        ->middleware('can:attendance.leave-requests.approve')
        ->name('attendance.leave-requests.approve');

    Route::put('attendance/leave-requests/{leave_request}/reject', [LeaveRequestController::class, 'reject'])
        ->middleware('can:attendance.leave-requests.approve')
        ->name('attendance.leave-requests.reject');

    Route::put('attendance/leave-requests/{leave_request}/cancel', [LeaveRequestController::class, 'cancel'])
        ->middleware('can:attendance.leave-requests.update')
        ->name('attendance.leave-requests.cancel');

    Route::get('attendance/leave-requests/{leave_request}/attachment', LeaveRequestAttachmentController::class)
        ->middleware('can:attendance.leave-requests.view')
        ->name('attendance.leave-requests.attachment');

    Route::get('hikvision/access-events', [HikvisionAccessEventController::class, 'index'])
        ->middleware('can:hikvision.events.view')
        ->name('hikvision.access-events.index');

    Route::post('hikvision/access-events/fetch', [HikvisionAccessEventController::class, 'fetch'])
        ->middleware('can:hikvision.events.fetch')
        ->name('hikvision.access-events.fetch');

    Route::get('mysql', [DatabaseViewerController::class, 'index'])->name('mysql.index');
    Route::get('mysql/query', [DatabaseViewerController::class, 'query'])->name('mysql.query');
    Route::post('mysql/query/execute', [DatabaseViewerController::class, 'execute'])->name('mysql.execute');
    Route::get('mysql/{table}', [DatabaseViewerController::class, 'show'])->name('mysql.show');
    Route::get('mysql/{table}/export', [DatabaseViewerController::class, 'export'])->name('mysql.export');
});
require __DIR__.'/settings.php';
