<?php

use App\Models\User;
use App\Support\EmployeeDocuments\DocumentExpiryNotification\DocumentExpiryRecipientDeliveryKey;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

function remigrateRecipientIdentityConversion(): void
{
    DB::table('migrations')
        ->where('migration', '2026_10_01_125956_convert_employee_document_expiry_alert_delivery_keys_to_recipient_identities')
        ->delete();

    Artisan::call('migrate', [
        '--force' => true,
        '--path' => 'database/migrations/2026_10_01_125956_convert_employee_document_expiry_alert_delivery_keys_to_recipient_identities.php',
    ]);
}

test('cohort delivery keys expand into evidenced recipient identities only', function () {
    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $rima = User::factory()->create(['email' => 'rima@example.com', 'status' => 'active']);
    grantCompanyPermissions($rima, $company, ['documents.view'], 'migrate-rima-role');

    $rabil = User::factory()->create(['email' => 'rabil@example.com', 'status' => 'active']);
    grantCompanyPermissions($rabil, $company, ['documents.view'], 'migrate-rabil-role');

    $newcomer = User::factory()->create(['email' => 'newcomer@example.com', 'status' => 'active']);
    grantCompanyPermissions($newcomer, $company, ['documents.view'], 'migrate-newcomer-role');

    $rule = createDocumentExpiryNotificationRule($company->id, [
        'to_user_ids' => [$rima->id, $newcomer->id],
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
    $alertedAt = now();

    DB::table('employee_document_expiry_alerts')->insert([
        'company_id' => $company->id,
        'notification_rule_id' => $rule->id,
        'employee_document_id' => $doc->id,
        'expiry_date_at_alert_time' => '2026-06-20',
        'delivery_key' => $cohortKey,
        'alerted_at' => $alertedAt,
        'created_at' => $alertedAt,
        'updated_at' => $alertedAt,
    ]);

    activity()
        ->useLog('documents')
        ->event('expiry_alert_sent')
        ->performedOn($company)
        ->withProperties([
            'to' => ['rima@example.com'],
            'cc' => ['rabil@example.com', 'manual@example.com'],
            'document_count' => 1,
            'company_id' => $company->id,
            'notification_rule_id' => $rule->id,
            'notification_rule_name' => $rule->name,
            'document_ids' => [$doc->id],
        ])
        ->tap(function (Activity $activity) use ($company): void {
            $activity->company_id = (int) $company->id;
        })
        ->log('Document expiry alert email sent');

    Activity::query()->latest('id')->first()?->forceFill([
        'created_at' => $alertedAt,
        'updated_at' => $alertedAt,
    ])->save();

    remigrateRecipientIdentityConversion();

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
        ->and($keys)->not->toContain(DocumentExpiryRecipientDeliveryKey::forUser($newcomer->id))
        ->and(DB::table('employee_document_expiry_alerts')->where('delivery_key', $cohortKey)->exists())->toBeFalse();

    remigrateRecipientIdentityConversion();

    expect(DB::table('employee_document_expiry_alerts')
        ->where('notification_rule_id', $rule->id)
        ->where('employee_document_id', $doc->id)
        ->count())->toBe(3);
});

test('cohort keys without activity evidence collapse to legacy wildcard', function () {
    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $rule = createDocumentExpiryNotificationRule($company->id, [
        'to_emails' => ['hr@example.com', 'later@example.com'],
        'cc_emails' => [],
    ]);

    $doc = createEmployeePdfDocument(
        $company->id,
        $employee->id,
        $passportType->id,
        "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf",
        'Passport.pdf',
    );

    $cohortKey = hash('sha256', 'to:hr@example.com|cc:');

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

    remigrateRecipientIdentityConversion();

    expect(DB::table('employee_document_expiry_alerts')
        ->where('employee_document_id', $doc->id)
        ->pluck('delivery_key')
        ->all())->toBe(['legacy'])
        ->and(DB::table('employee_document_expiry_alerts')
            ->where('delivery_key', DocumentExpiryRecipientDeliveryKey::forEmail('later@example.com'))
            ->exists())->toBeFalse();
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

    remigrateRecipientIdentityConversion();

    expect(DB::table('employee_document_expiry_alerts')
        ->where('employee_document_id', $doc->id)
        ->pluck('delivery_key')
        ->all())->toBe(['legacy']);
});
