<?php

use App\Support\EmployeeDocuments\DocumentAiExtractionResult;

it('normalizes a strict extraction payload', function () {
    $result = DocumentAiExtractionResult::fromDecoded([
        'document_type' => 'passport',
        'confidence' => 0.94,
        'fields' => [
            'document_number' => ['value' => ' P123 ', 'confidence' => 0.98],
            'expiry_date' => ['value' => null, 'confidence' => null],
        ],
        'warnings' => ['Expiry date unclear'],
    ]);

    expect($result->toArray())->toMatchArray([
        'document_type' => 'passport',
        'confidence' => 0.94,
        'fields' => [
            'document_number' => ['value' => 'P123', 'confidence' => 0.98],
            'expiry_date' => ['value' => null, 'confidence' => null],
        ],
        'warnings' => ['Expiry date unclear'],
    ]);
});

it('rejects malformed extraction payloads', function () {
    DocumentAiExtractionResult::fromDecoded([
        'document_type' => 'passport',
        'confidence' => 2,
        'fields' => [],
        'warnings' => [],
    ]);
})->throws(InvalidArgumentException::class);
