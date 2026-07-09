<?php

use App\Services\Settings\SettingService;
use App\Support\BulkDocuments\BulkDocumentSignaturePlacementService;
use App\Support\BulkDocuments\BulkDocumentTypeRegistry;
use App\Support\BulkDocuments\SalaryDeclarationSignaturePlacements;
use App\Support\Settings\SettingKey;

/**
 * @return array{
 *     signature: array{left: float, top: float, width: float, height: float},
 *     date: array{left: float, top: float, width: float, height: float},
 *     signature_ar: array{left: float, top: float, width: float, height: float},
 *     date_ar: array{left: float, top: float, width: float, height: float}
 * }
 */
function sampleEditorRects(): array
{
    return [
        'signature' => [
            'left' => 40.0,
            'top' => 500.0,
            'width' => 280.0,
            'height' => 60.0,
        ],
        'date' => [
            'left' => 40.0,
            'top' => 580.0,
            'width' => 160.0,
            'height' => 30.0,
        ],
        'signature_ar' => [
            'left' => 480.0,
            'top' => 500.0,
            'width' => 280.0,
            'height' => 60.0,
        ],
        'date_ar' => [
            'left' => 480.0,
            'top' => 580.0,
            'width' => 160.0,
            'height' => 30.0,
        ],
    ];
}

test('defaults are used when placement setting is empty', function () {
    $service = app(BulkDocumentSignaturePlacementService::class);

    $placement = $service->resolve(SalaryDeclarationSignaturePlacements::DOCUMENT_TYPE_KEY);

    expect($placement)->toBe(SalaryDeclarationSignaturePlacements::config());
});

test('save and resolve round-trip custom placement', function () {
    $service = app(BulkDocumentSignaturePlacementService::class);
    $rects = sampleEditorRects();

    $config = $service->fromEditorRects(
        signature: $rects['signature'],
        date: $rects['date'],
        signatureAr: $rects['signature_ar'],
        dateAr: $rects['date_ar'],
        canvasWidth: 800,
        canvasHeight: 1131,
        page: 1,
    );

    $service->save(SalaryDeclarationSignaturePlacements::DOCUMENT_TYPE_KEY, $config);

    expect($service->resolve(SalaryDeclarationSignaturePlacements::DOCUMENT_TYPE_KEY))
        ->toBe($config);
});

test('fromEditorRects preserves independent arabic row positions from editor', function () {
    $service = app(BulkDocumentSignaturePlacementService::class);
    $rects = sampleEditorRects();

    $rects['signature_ar']['top'] = 300.0;
    $rects['date_ar']['top'] = 400.0;

    $config = $service->fromEditorRects(
        signature: $rects['signature'],
        date: $rects['date'],
        signatureAr: $rects['signature_ar'],
        dateAr: $rects['date_ar'],
        canvasWidth: 800,
        canvasHeight: 1131,
    );

    $enImage = $config['stamps'][0];
    $arImage = $config['stamps'][1];
    $enDate = $config['stamps'][2];
    $arDate = $config['stamps'][3];

    expect($arImage['y'])->not->toBe($enImage['y'])
        ->and($arDate['y'])->not->toBe($enDate['y'])
        ->and($arImage['y'])->toBe(78.78)
        ->and($arDate['y'])->toBe(112.92);
});

test('fromEditorRects stores explicit english and arabic stamp positions', function () {
    $service = app(BulkDocumentSignaturePlacementService::class);
    $rects = sampleEditorRects();

    $config = $service->fromEditorRects(
        signature: $rects['signature'],
        date: $rects['date'],
        signatureAr: $rects['signature_ar'],
        dateAr: $rects['date_ar'],
        canvasWidth: 800,
        canvasHeight: 1131,
    );

    expect($config['overlay'])->toMatchArray([
        'left' => '5%',
        'top' => '44.2087%',
        'width' => '35%',
        'height' => '5.305%',
    ]);

    expect($config['stamps'])->toHaveCount(4);

    $enImage = $config['stamps'][0];
    $arImage = $config['stamps'][1];
    $enDate = $config['stamps'][2];
    $arDate = $config['stamps'][3];

    expect($enImage['type'])->toBe('image')
        ->and($arImage['type'])->toBe('image')
        ->and($enDate['type'])->toBe('date')
        ->and($arDate['type'])->toBe('date');

    expect($arImage['x'])->toBe(126.0)
        ->and($arImage['y'])->toBe($enImage['y'])
        ->and($arImage['w'])->toBe($enImage['w'])
        ->and($arImage['h'])->toBe($enImage['h']);

    expect($arDate['x'])->toBe(126.0)
        ->and($arDate['y'])->toBe($enDate['y']);
});

test('editorRectsFromConfig restores all four placement boxes', function () {
    $service = app(BulkDocumentSignaturePlacementService::class);
    $rects = sampleEditorRects();

    $config = $service->fromEditorRects(
        signature: $rects['signature'],
        date: $rects['date'],
        signatureAr: $rects['signature_ar'],
        dateAr: $rects['date_ar'],
        canvasWidth: 800,
        canvasHeight: 1131,
    );

    $restored = $service->editorRectsFromConfig($config, 800, 1131);

    expect($restored['signature']['left'])->toBe(40.0)
        ->and($restored['signature_ar']['left'])->toBe(480.0)
        ->and($restored['date_ar']['left'])->toBe(480.0);
});

test('reset restores defaults', function () {
    $service = app(BulkDocumentSignaturePlacementService::class);
    $rects = sampleEditorRects();

    $service->save(
        SalaryDeclarationSignaturePlacements::DOCUMENT_TYPE_KEY,
        $service->fromEditorRects(
            signature: $rects['signature'],
            date: $rects['date'],
            signatureAr: $rects['signature_ar'],
            dateAr: $rects['date_ar'],
            canvasWidth: 400,
            canvasHeight: 600,
        ),
    );

    $service->resetToDefaults(SalaryDeclarationSignaturePlacements::DOCUMENT_TYPE_KEY);

    expect(app(SettingService::class)->get(SettingKey::BulkDocumentSignaturePlacementSalaryDeclaration))->toBeNull()
        ->and($service->resolve(SalaryDeclarationSignaturePlacements::DOCUMENT_TYPE_KEY))
        ->toBe(SalaryDeclarationSignaturePlacements::config());
});

test('resolve preserves arabic date row when y is offset from english', function () {
    $service = app(BulkDocumentSignaturePlacementService::class);
    $rects = sampleEditorRects();

    $config = $service->fromEditorRects(
        signature: $rects['signature'],
        date: $rects['date'],
        signatureAr: $rects['signature_ar'],
        dateAr: $rects['date_ar'],
        canvasWidth: 800,
        canvasHeight: 1131,
    );

    $config['stamps'][3]['y'] = 175.68;

    $service->save(SalaryDeclarationSignaturePlacements::DOCUMENT_TYPE_KEY, $config);

    $resolved = $service->resolve(SalaryDeclarationSignaturePlacements::DOCUMENT_TYPE_KEY);

    expect($resolved['stamps'][3]['y'])->toBe(175.68)
        ->and($resolved['stamps'][3]['y'])->not->toBe($config['stamps'][2]['y']);
});

test('bulk document type registry resolves saved placement', function () {
    $service = app(BulkDocumentSignaturePlacementService::class);
    $rects = sampleEditorRects();

    $config = $service->fromEditorRects(
        signature: [
            'left' => 20,
            'top' => 300,
            'width' => 200,
            'height' => 50,
        ],
        date: [
            'left' => 20,
            'top' => 360,
            'width' => 120,
            'height' => 24,
        ],
        signatureAr: [
            'left' => 320,
            'top' => 300,
            'width' => 200,
            'height' => 50,
        ],
        dateAr: [
            'left' => 320,
            'top' => 360,
            'width' => 120,
            'height' => 24,
        ],
        canvasWidth: 600,
        canvasHeight: 900,
    );

    $service->save(SalaryDeclarationSignaturePlacements::DOCUMENT_TYPE_KEY, $config);

    expect(BulkDocumentTypeRegistry::resolveSignaturePlacements('salary_declaration'))
        ->toBe($config);
});
