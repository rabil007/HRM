<?php

use App\Models\DocumentType;
use App\Support\EmployeeDocuments\UnmappedEmployeeDocumentTypeMatcher;

test('matcher classifies exact and normalized titles and leaves unmatched values unmatched', function () {
    ['passportType' => $passportType] = makeDocumentFixtures();
    $matcher = new UnmappedEmployeeDocumentTypeMatcher;

    expect($matcher->classify($passportType->title)['status'])->toBe('match')
        ->and($matcher->classify($passportType->title)['document_type_id'])->toBe($passportType->id)
        ->and($matcher->classify('  '.$passportType->title.'  ')['status'])->toBe('match')
        ->and($matcher->classify(mb_strtoupper($passportType->title))['status'])->toBe('match')
        ->and($matcher->classify('passport-copy')['status'])->toBe('unmatched')
        ->and($matcher->classify('')['status'])->toBe('unmatched');
});

test('matcher treats two types that normalize to the same title as ambiguous', function () {
    makeDocumentFixtures();
    DocumentType::query()->create(['title' => 'Seafarer Medical', 'is_active' => true]);
    DocumentType::query()->create(['title' => 'seafarer medical', 'is_active' => true]);

    $matcher = new UnmappedEmployeeDocumentTypeMatcher;

    expect($matcher->classify('Seafarer Medical')['status'])->toBe('ambiguous')
        ->and($matcher->classify('seafarer medical')['document_type_id'])->toBeNull();
});
