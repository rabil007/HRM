<?php

use App\Models\User;
use App\Support\Uploads\FailedUploadLogger;
use App\Support\Uploads\UploadedFileStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

test('failed document upload validation is logged globally', function () {
    Event::fake([MessageLogged::class]);
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.upload']);

    $this->post("/organization/employees/{$employee->id}/documents", [
        'document_type_id' => $passportType->id,
        'file' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
    ])->assertSessionHasErrors('file');

    Event::assertDispatched(
        MessageLogged::class,
        fn (MessageLogged $event) => $event->level === 'error'
            && $event->message === FailedUploadLogger::LOG_MESSAGE
            && ($event->context['reason'] ?? '') === 'Upload request failed validation.',
    );
});

test('successful document upload is not logged as a failure', function () {
    Event::fake([MessageLogged::class]);
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.upload']);

    $this->post("/organization/employees/{$employee->id}/documents", [
        'document_type_id' => $passportType->id,
        'file' => UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf'),
    ])->assertRedirect();

    Event::assertNotDispatched(
        MessageLogged::class,
        fn (MessageLogged $event) => $event->level === 'error'
            && $event->message === FailedUploadLogger::LOG_MESSAGE,
    );
});

test('uploaded file storage failures are logged with file context', function () {
    Event::fake([MessageLogged::class]);

    $file = new UploadedFile(
        __FILE__,
        'broken.pdf',
        'application/pdf',
        UPLOAD_ERR_PARTIAL,
        true,
    );

    expect(fn () => UploadedFileStorage::store($file, 'employee-documents', 'public'))
        ->toThrow(RuntimeException::class);

    Event::assertDispatched(
        MessageLogged::class,
        fn (MessageLogged $event) => $event->level === 'error'
            && $event->message === FailedUploadLogger::LOG_MESSAGE
            && ($event->context['failure_stage'] ?? null) === 'storage'
            && ($event->context['file']['original_name'] ?? null) === 'broken.pdf',
    );
});
