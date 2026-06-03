<?php

use App\Models\Employee;
use App\Models\User;
use App\Models\WhatsAppSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

test('guests cannot send whatsapp document templates', function () {
    $this->postJson(route('organization.documents.employee.files.whatsapp-template', [
        'employee' => 1,
        'document' => 1,
    ]))->assertUnauthorized();
});

test('whatsapp document template requires documents share permission', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.view']);

    $path = "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf";
    $doc = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, $path, 'Passport.pdf');

    $this->postJson(route('organization.documents.employee.files.whatsapp-template', [
        'employee' => $employee,
        'document' => $doc,
    ]), [
        'whatsapp_number' => '+971501234567',
        'template_slug' => 'document_delivery',
    ])->assertForbidden();
});

test('whatsapp document template requires whatsapp number', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.view', 'documents.share']);

    WhatsAppSetting::current()->storeFromValidated([
        'business_account_id' => '123456789',
        'phone_number_id' => '987654321',
        'access_token' => 'valid-token',
        'app_id' => 'app-id-123',
        'app_secret' => 'secret',
        'webhook_verify_token' => 'verify-token-abc',
        'enabled' => true,
    ]);

    $path = "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf";
    $doc = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, $path, 'Passport.pdf');

    $this->postJson(route('organization.documents.employee.files.whatsapp-template', [
        'employee' => $employee,
        'document' => $doc,
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['whatsapp_number']);
});

test('whatsapp document template sends successfully', function () {
    Http::fake([
        'graph.facebook.com/*' => Http::response([
            'messaging_product' => 'whatsapp',
            'messages' => [['id' => 'wamid.template-doc-id']],
        ], 200),
    ]);

    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $employee->update(['phone' => '+971501234567']);

    grantCompanyPermissions($user, $company, ['documents.view', 'documents.share']);

    WhatsAppSetting::current()->storeFromValidated([
        'business_account_id' => '123456789',
        'phone_number_id' => '987654321',
        'access_token' => 'valid-token',
        'app_id' => 'app-id-123',
        'app_secret' => 'secret',
        'webhook_verify_token' => 'verify-token-abc',
        'enabled' => true,
    ]);

    $path = "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf";
    $doc = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, $path, 'Passport.pdf');

    $response = $this->postJson(route('organization.documents.employee.files.whatsapp-template', [
        'employee' => $employee,
        'document' => $doc,
    ]), [
        'whatsapp_number' => '+971501234567',
        'template_slug' => 'document_delivery',
    ]);

    $response->assertOk()
        ->assertJson([
            'message' => 'Document template sent via WhatsApp.',
            'message_id' => 'wamid.template-doc-id',
            'normalized_phone' => '971501234567',
        ]);

    Http::assertSent(function ($request) {
        $body = $request->data();

        return ($body['type'] ?? null) === 'template'
            && ($body['template']['name'] ?? null) === 'document_delivery'
            && ($body['template']['language']['code'] ?? null) === 'en'
            && ($body['template']['components'][0]['parameters'][0]['type'] ?? null) === 'document'
            && str_starts_with(
                (string) ($body['template']['components'][0]['parameters'][0]['document']['link'] ?? ''),
                'http',
            )
            && ($body['template']['components'][1]['parameters'][0]['text'] ?? null) === 'Test Employee';
    });
});

test('whatsapp document template returns error when meta api fails', function () {
    Http::fake([
        'graph.facebook.com/*' => Http::response([
            'error' => [
                'message' => 'Template name does not exist in the translation',
                'type' => 'OAuthException',
                'code' => 132001,
            ],
        ], 404),
    ]);

    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $employee->update(['phone' => '+971501234567']);

    grantCompanyPermissions($user, $company, ['documents.view', 'documents.share']);

    WhatsAppSetting::current()->storeFromValidated([
        'business_account_id' => '123456789',
        'phone_number_id' => '987654321',
        'access_token' => 'valid-token',
        'app_id' => 'app-id-123',
        'app_secret' => 'secret',
        'webhook_verify_token' => 'verify-token-abc',
        'enabled' => true,
    ]);

    $path = "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf";
    $doc = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, $path, 'Passport.pdf');

    $this->postJson(route('organization.documents.employee.files.whatsapp-template', [
        'employee' => $employee,
        'document' => $doc,
    ]), [
        'whatsapp_number' => '+971501234567',
        'template_slug' => 'document_delivery',
    ])->assertUnprocessable()
        ->assertJson([
            'message' => 'Template name does not exist in the translation',
        ]);
});

test('users cannot send whatsapp document template for another employees document', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $employee->update(['phone' => '+971501234567']);

    grantCompanyPermissions($user, $company, ['documents.view', 'documents.share']);

    WhatsAppSetting::current()->storeFromValidated([
        'business_account_id' => '123456789',
        'phone_number_id' => '987654321',
        'access_token' => 'valid-token',
        'app_id' => 'app-id-123',
        'app_secret' => 'secret',
        'webhook_verify_token' => 'verify-token-abc',
        'enabled' => true,
    ]);

    $otherEmployee = Employee::factory()->create([
        'company_id' => $company->id,
        'branch_id' => $employee->branch_id,
        'phone' => '+971509999999',
    ]);

    $path = "employee-documents/{$company->id}/{$otherEmployee->id}/passport/a.pdf";
    $doc = createEmployeePdfDocument($company->id, $otherEmployee->id, $passportType->id, $path, 'Passport.pdf');

    $this->postJson(route('organization.documents.employee.files.whatsapp-template', [
        'employee' => $employee,
        'document' => $doc,
    ]), [
        'whatsapp_number' => '+971501234567',
        'template_slug' => 'document_delivery',
    ])->assertNotFound();
});
