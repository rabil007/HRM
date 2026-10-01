<?php

use App\Models\User;
use App\Support\EmployeeDocuments\DocumentExpiryNotification\DocumentExpiryRecipientDeliveryKey;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

test('cohort delivery keys expand into current rule recipient identities', function () {
    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $rima = User::factory()->create(['email' => 'rima@example.com', 'status' => 'active']);
    grantCompanyPermissions($rima, $company, ['documents.view'], 'migrate-rima-role');

    $rabil = User::factory()->create(['email' => 'rabil@example.com', 'status' => 'active']);
    grantCompanyPermissions($rabil, $company, ['documents.view'], 'migrate-rabil-role');

    $rule = createDocumentExpiryNotificationRule($company->id, [
        'to_user_ids' => [$rima->id],
        'to_emails' => [],
        'cc_user_ids' => [$rabil->id],
        'cc_emails' => ['manual@example.com'],
    ]);

    $doc = createEmployeePdfDocument(
        $company->id,
        $employee->id,
        $passportType->id,
        "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf",
        'Passport.pdf',
    );
    $doc->update(['expiry_date' => '2026-06-20']);

    $cohortKey = hash('sha256', 'to:rima@example.com|cc:manual@example.com,rabil@example.com');

    DB::table('employee_document_expiry_alerts')->insert([
        'company_id' => $company->id,
        'notification_rule_id' => $rule->id,
        'employee_document_id' => $doc->id,
        'expiry_date_at_alert_time' => '2026-06-20',
        'delivery_key' => $cohortKey,
        'alerted_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('migrations')
        ->where('migration', '2026_10_01_125956_convert_employee_document_expiry_alert_delivery_keys_to_recipient_identities')
        ->delete();

    Artisan::call('migrate', [
        '--force' => true,
        '--path' => 'database/migrations/2026_10_01_125956_convert_employee_document_expiry_alert_delivery_keys_to_recipient_identities.php',
    ]);

    $keys = DB::table('employee_document_expiry_alerts')
        ->where('notification_rule_id', $rule->id)
        ->where('employee_document_id', $doc->id)
        ->pluck('delivery_key')
        ->sort()
        ->values()
        ->all();

    expect($keys)->toEqualCanonicalizing([
        DocumentExpiryRecipientDeliveryKey::forUser($rima->id),
        DocumentExpiryRecipientDeliveryKey::forUser($rabil->id),
        DocumentExpiryRecipientDeliveryKey::forEmail('manual@example.com'),
    ])
        ->and(DB::table('employee_document_expiry_alerts')->where('delivery_key', $cohortKey)->exists())->toBeFalse();

    // Idempotent on re-run.
    DB::table('migrations')
        ->where('migration', '2026_10_01_125956_convert_employee_document_expiry_alert_delivery_keys_to_recipient_identities')
        ->delete();

    Artisan::call('migrate', [
        '--force' => true,
        '--path' => 'database/migrations/2026_10_01_125956_convert_employee_document_expiry_alert_delivery_keys_to_recipient_identities.php',
    ]);

    expect(DB::table('employee_document_expiry_alerts')
        ->where('notification_rule_id', $rule->id)
        ->where('employee_document_id', $doc->id)
        ->count())->toBe(3);
});

test('legacy delivery keys remain untouched by recipient identity conversion', function () {
    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $rule = createDocumentExpiryNotificationRule($company->id, [
        'to_emails' => ['hr@example.com'],
        'cc_emails' => [],
    ]);

    $doc = createEmployeePdfDocument(
        $company->id,
        $employee->id,
        $passportType->id,
        "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf",
        'Passport.pdf',
    );

    DB::table('employee_document_expiry_alerts')->insert([
        'company_id' => $company->id,
        'notification_rule_id' => $rule->id,
        'employee_document_id' => $doc->id,
        'expiry_date_at_alert_time' => '2026-06-20',
        'delivery_key' => 'legacy',
        'alerted_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('migrations')
        ->where('migration', '2026_10_01_125956_convert_employee_document_expiry_alert_delivery_keys_to_recipient_identities')
        ->delete();

    Artisan::call('migrate', [
        '--force' => true,
        '--path' => 'database/migrations/2026_10_01_125956_convert_employee_document_expiry_alert_delivery_keys_to_recipient_identities.php',
    ]);

    expect(DB::table('employee_document_expiry_alerts')
        ->where('employee_document_id', $doc->id)
        ->pluck('delivery_key')
        ->all())->toBe(['legacy']);
});
