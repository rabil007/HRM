<?php

use App\Support\Documents\RecipientRequests\DocumentSignaturePlacementValidator;

function v3Placement(string $id, string $slot, string $role, float $x = 0.1, int $page = 1): array
{
    return [
        'id' => $id,
        'type' => 'signature',
        'role' => $role,
        'slot_key' => $slot,
        'page' => $page,
        'x' => $x,
        'y' => 0.7,
        'width' => 0.25,
        'height' => 0.08,
        'required' => true,
    ];
}

test('v3 allows two placements with the same subject slot_key', function () {
    $config = [
        'schema_version' => 3,
        'placements' => [
            v3Placement('employee_signature_en', 'subject', 'subject', 0.1),
            v3Placement('employee_signature_ar', 'subject', 'subject', 0.5),
        ],
    ];

    $validated = DocumentSignaturePlacementValidator::validateSignaturePlacementConfig($config, 1);

    expect($validated['schema_version'])->toBe(3)
        ->and($validated['placements'])->toHaveCount(2)
        ->and(DocumentSignaturePlacementValidator::validateSignaturesForSlot($config, 1, 'subject'))
        ->toHaveCount(2);
});

test('v3 rejects duplicate physical placement ids', function () {
    $config = [
        'schema_version' => 3,
        'placements' => [
            v3Placement('subject_signature', 'subject', 'subject', 0.1),
            v3Placement('subject_signature', 'subject', 'subject', 0.5),
        ],
    ];

    expect(fn () => DocumentSignaturePlacementValidator::validateSignaturePlacementConfig($config, 1))
        ->toThrow(InvalidArgumentException::class, 'Duplicate signature placement ids');
});

test('v3 rejects role and slot_key mismatch', function () {
    $config = [
        'schema_version' => 3,
        'placements' => [
            v3Placement('manager_signature', 'subject', 'manager'),
        ],
    ];

    expect(fn () => DocumentSignaturePlacementValidator::validateSignaturePlacementConfig($config, 1))
        ->toThrow(InvalidArgumentException::class, 'must match');
});

test('v3 allows multiple manager_1 placements plus manager_2', function () {
    $config = [
        'schema_version' => 3,
        'placements' => [
            v3Placement('manager_signature', 'manager_1', 'manager', 0.1),
            v3Placement('manager_signature_copy', 'manager_1', 'manager', 0.4),
            v3Placement('manager_signature_2', 'manager_2', 'manager', 0.7),
        ],
    ];

    $validated = DocumentSignaturePlacementValidator::validateSignaturePlacementConfig($config, 1);

    expect($validated['placements'])->toHaveCount(3)
        ->and(DocumentSignaturePlacementValidator::validateSignaturesForSlot($config, 1, 'manager_1'))->toHaveCount(2)
        ->and(DocumentSignaturePlacementValidator::validateSignaturesForSlot($config, 1, 'manager_2'))->toHaveCount(1);
});

test('v3 still rejects sparse manager_1 plus manager_3', function () {
    $config = [
        'schema_version' => 3,
        'placements' => [
            v3Placement('manager_signature', 'manager_1', 'manager', 0.1),
            v3Placement('manager_signature_3', 'manager_3', 'manager', 0.5),
        ],
    ];

    expect(fn () => DocumentSignaturePlacementValidator::validateSignaturePlacementConfig($config, 1))
        ->toThrow(InvalidArgumentException::class, 'contiguous');
});

test('v2 still rejects duplicate slot keys', function () {
    $config = [
        'schema_version' => 2,
        'placements' => [
            v3Placement('employee_signature_en', 'subject', 'subject', 0.1),
            v3Placement('employee_signature_ar', 'subject', 'subject', 0.5),
        ],
    ];

    expect(fn () => DocumentSignaturePlacementValidator::validateSignaturePlacementConfig($config, 1))
        ->toThrow(InvalidArgumentException::class, 'Duplicate signature slot keys');
});

test('v1 remains valid with one placement per role', function () {
    $config = [
        'schema_version' => 1,
        'placements' => [[
            'id' => 'subject_signature',
            'type' => 'signature',
            'role' => 'subject',
            'page' => 1,
            'x' => 0.1,
            'y' => 0.7,
            'width' => 0.25,
            'height' => 0.08,
            'required' => true,
        ]],
    ];

    $validated = DocumentSignaturePlacementValidator::validateSignaturePlacementConfig($config, 1);

    expect($validated['schema_version'])->toBe(1)
        ->and(DocumentSignaturePlacementValidator::validateSignaturesForSlot($config, 1, 'subject'))->toHaveCount(1);
});

test('draft save normalizes v1 and v2 to schema v3', function () {
    $v1 = [
        'schema_version' => 1,
        'placements' => [[
            'id' => 'subject_signature',
            'type' => 'signature',
            'role' => 'subject',
            'page' => 1,
            'x' => 0.1,
            'y' => 0.7,
            'width' => 0.25,
            'height' => 0.08,
            'required' => true,
        ]],
    ];

    $normalized = DocumentSignaturePlacementValidator::normalizeForDraftSave($v1, 1);

    expect($normalized['schema_version'])->toBe(3)
        ->and($normalized['placements'][0]['slot_key'])->toBe('subject')
        ->and($normalized['placements'][0]['text_align'])->toBe('center')
        ->and($normalized['placements'][0]['vertical_align'])->toBe('middle');
});

test('v3 persists signature alignment and rejects invalid values', function () {
    $config = [
        'schema_version' => 3,
        'placements' => [
            array_merge(v3Placement('subject_signature', 'subject', 'subject'), [
                'text_align' => 'right',
                'vertical_align' => 'baseline',
            ]),
        ],
    ];

    $validated = DocumentSignaturePlacementValidator::validateSignaturePlacementConfig($config, 1);

    expect($validated['placements'][0]['text_align'])->toBe('right')
        ->and($validated['placements'][0]['vertical_align'])->toBe('baseline');

    $invalid = $config;
    $invalid['placements'][0]['text_align'] = 'justify';

    expect(fn () => DocumentSignaturePlacementValidator::validateSignaturePlacementConfig($invalid, 1))
        ->toThrow(InvalidArgumentException::class, 'invalid text alignment');
});
