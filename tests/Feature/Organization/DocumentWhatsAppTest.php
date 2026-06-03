<?php

use App\Models\User;
use App\Models\WhatsAppSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

test('guests cannot send employee documents via whatsapp', function () {
    $this->postJson(route('organization.documents.employee.files.whatsapp', 1), [
        'document_ids' => [1],
        'whatsapp_number' => '+971501234567',
    ])->assertUnauthorized();
});

test('users can send selected employee documents via whatsapp api', function () {
    Http::fake([
        'graph.facebook.com/*/media' => Http::response(['id' => 'media-doc-123'], 200),
        'graph.facebook.com/*/messages' => Http::response([
            'messaging_product' => 'whatsapp',
            'messages' => [['id' => 'wamid.doc-send-id']],
        ], 200),
    ]);

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

    $response = $this->postJson(route('organization.documents.employee.files.whatsapp', $employee), [
        'document_ids' => [$doc->id],
        'whatsapp_number' => '+971501234567',
        'send_template_first' => false,
    ]);

    $response->assertOk()
        ->assertJson([
            'sent_count' => 1,
            'failed_count' => 0,
        ])
        ->assertJsonPath('results.0.document_name', 'Passport.pdf')
        ->assertJsonPath('results.0.message_id', 'wamid.doc-send-id');
});

test('whatsapp direct send can include hello_world template first', function () {
    Http::fake([
        'graph.facebook.com/*/media' => Http::response(['id' => 'media-doc-456'], 200),
        'graph.facebook.com/*/messages' => Http::sequence()
            ->push([
                'messaging_product' => 'whatsapp',
                'messages' => [['id' => 'wamid.template-id']],
            ], 200)
            ->push([
                'messaging_product' => 'whatsapp',
                'messages' => [['id' => 'wamid.doc-send-id']],
            ], 200),
    ]);

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

    $response = $this->postJson(route('organization.documents.employee.files.whatsapp', $employee), [
        'document_ids' => [$doc->id],
        'whatsapp_number' => '+971501234567',
        'send_template_first' => true,
    ]);

    $response->assertOk()
        ->assertJson([
            'sent_count' => 2,
            'failed_count' => 0,
        ])
        ->assertJsonPath('results.0.document_name', 'hello_world template')
        ->assertJsonPath('results.1.message_id', 'wamid.doc-send-id');
});

test('whatsapp direct send fails when integration is not configured', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.view', 'documents.share']);

    $path = "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf";
    $doc = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, $path, 'Passport.pdf');

    $this->postJson(route('organization.documents.employee.files.whatsapp', $employee), [
        'document_ids' => [$doc->id],
        'whatsapp_number' => '+971501234567',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['whatsapp']);
});
