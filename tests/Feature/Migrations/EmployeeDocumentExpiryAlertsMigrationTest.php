<?php

use App\Models\Company;
use Database\Seeders\CompanySeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('legacy employee document expiry alerts table can be upgraded', function () {
    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $document = createEmployeePdfDocument(
        $company->id,
        $employee->id,
        $passportType->id,
        "employee-documents/{$company->id}/{$employee->id}/passport/legacy.pdf",
        'Legacy.pdf',
    );
    $document->update(['expiry_date' => '2026-07-01']);

    Schema::dropIfExists('employee_document_expiry_alerts');

    Schema::create('employee_document_expiry_alerts', function (Blueprint $table) {
        $table->id();
        $table->foreignId('company_id')->constrained()->cascadeOnDelete();
        $table->foreignId('employee_document_id')->constrained()->cascadeOnDelete();
        $table->timestamp('sent_at')->nullable();
        $table->timestamps();
        $table->unique('employee_document_id');
    });

    DB::table('employee_document_expiry_alerts')->insert([
        'company_id' => $company->id,
        'employee_document_id' => $document->id,
        'sent_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('migrations')
        ->where('migration', '2026_06_03_122440_add_expiry_date_at_alert_time_to_employee_document_expiry_alerts_table')
        ->delete();

    Artisan::call('migrate', [
        '--force' => true,
        '--path' => 'database/migrations/2026_06_03_122440_add_expiry_date_at_alert_time_to_employee_document_expiry_alerts_table.php',
    ]);

    expect(Schema::hasColumn('employee_document_expiry_alerts', 'expiry_date_at_alert_time'))->toBeTrue()
        ->and(Schema::hasColumn('employee_document_expiry_alerts', 'alerted_at'))->toBeTrue()
        ->and(Schema::hasColumn('employee_document_expiry_alerts', 'sent_at'))->toBeFalse();

    $row = DB::table('employee_document_expiry_alerts')
        ->where('employee_document_id', $document->id)
        ->first();

    expect($row->expiry_date_at_alert_time)->toBe('2026-07-01')
        ->and($row->alerted_at)->not->toBeNull();
});

test('company seeder does not duplicate slug when company is soft deleted', function () {
    makeDocumentFixtures();

    $this->seed(CompanySeeder::class);

    $company = Company::query()->where('slug', 'herd-oms')->firstOrFail();
    $company->delete();

    expect($company->trashed())->toBeTrue();

    $this->seed(CompanySeeder::class);

    $restored = Company::query()->where('slug', 'herd-oms')->first();

    expect($restored)->not->toBeNull()
        ->and($restored->trashed())->toBeFalse()
        ->and(Company::withTrashed()->where('slug', 'herd-oms')->count())->toBe(1);
});
