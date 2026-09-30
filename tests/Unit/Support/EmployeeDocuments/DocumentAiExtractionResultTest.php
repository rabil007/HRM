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

it('whitelists fields and normalizes invalid dates to warnings', function () {
    $result = DocumentAiExtractionResult::fromDecoded([
        'document_type' => 'unknown',
        'confidence' => 0.7,
        'fields' => [
            'issue_date' => ['value' => '2029-04-30', 'confidence' => 0.8],
            'expiry_date' => ['value' => '03/04/28', 'confidence' => 0.5],
            'sql' => ['value' => 'drop table employees', 'confidence' => 1],
        ],
        'warnings' => [],
    ])->toArray();

    expect($result['fields'])->toHaveKey('issue_date')
        ->not->toHaveKey('sql')
        ->and($result['fields']['issue_date']['value'])->toBe('2029-04-30')
        ->and($result['fields']['expiry_date']['value'])->toBeNull()
        ->and($result['warnings'])->toContain('Expiry date could not be normalized and needs manual review.');
});
