<?php

use App\Models\BulkDocumentEmailBatch;
use App\Models\BulkDocumentEmailSend;
use App\Models\BulkDocumentGenerationRun;
use App\Models\BulkDocumentSignatureRepairRun;
use App\Models\BulkDocumentSignatureRequest;
use App\Models\CrewOperationsSetting;
use App\Models\CrewTimesheet;
use App\Models\JobRun;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\SalaryInput;
use App\Models\SalaryInputType;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;

test('remaining domain models use soft deletes', function (string $modelClass) {
    expect(in_array(SoftDeletes::class, class_uses_recursive($modelClass), true))->toBeTrue();
})->with([
    SalaryInputType::class,
    PayrollPeriod::class,
    PayrollRecord::class,
    CrewTimesheet::class,
    SalaryInput::class,
    CrewOperationsSetting::class,
    JobRun::class,
    BulkDocumentGenerationRun::class,
    BulkDocumentEmailBatch::class,
    BulkDocumentEmailSend::class,
    BulkDocumentSignatureRequest::class,
    BulkDocumentSignatureRepairRun::class,
]);

test('remaining soft-deleted tables have deleted_at column', function (string $table) {
    expect(Schema::hasColumn($table, 'deleted_at'))->toBeTrue();
})->with([
    'salary_input_types',
    'payroll_periods',
    'payroll_records',
    'crew_timesheets',
    'salary_inputs',
    'crew_operations_settings',
    'job_runs',
    'bulk_document_generation_runs',
    'bulk_document_email_batches',
    'bulk_document_email_sends',
    'bulk_document_signature_requests',
    'bulk_document_signature_repair_runs',
]);
