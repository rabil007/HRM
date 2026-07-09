<?php

use App\Services\Settings\SettingService;
use App\Support\BulkDocuments\BulkDocumentSignaturePlacementService;
use App\Support\BulkDocuments\BulkDocumentTypeRegistry;
use App\Support\BulkDocuments\SalaryDeclarationSignaturePlacements;
use App\Support\Settings\SettingKey;

test('defaults are used when placement setting is empty', function () {
    $service = app(BulkDocumentSignaturePlacementService::class);

    $placement = $service->resolve(SalaryDeclarationSignaturePlacements::DOCUMENT_TYPE_KEY);

    expect($placement)->toBe(SalaryDeclarationSignaturePlacements::config());
});

test('save and resolve round-trip custom placement', function () {
    $service = app(BulkDocumentSignaturePlacementService::class);

    $config = $service->fromEditorRects(
        signatureLeft: 40,
        signatureTop: 500,
        signatureWidth: 280,
        signatureHeight: 60,
        dateLeft: 40,
        dateTop: 580,
        dateWidth: 160,
        dateHeight: 30,
        canvasWidth: 800,
        canvasHeight: 1131,
        page: 1,
    );

    $service->save(SalaryDeclarationSignaturePlacements::DOCUMENT_TYPE_KEY, $config);

    expect($service->resolve(SalaryDeclarationSignaturePlacements::DOCUMENT_TYPE_KEY))
        ->toBe($config);
});

test('fromEditorRects produces overlay percentages and mirrored arabic stamps', function () {
    $service = app(BulkDocumentSignaturePlacementService::class);

    $config = $service->fromEditorRects(
        signatureLeft: 40,
        signatureTop: 500,
        signatureWidth: 280,
        signatureHeight: 60,
        dateLeft: 40,
        dateTop: 580,
        dateWidth: 160,
        dateHeight: 30,
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

    expect($arImage['x'])->toBe(round(210 - $enImage['x'] - $enImage['w'], 2))
        ->and($arImage['y'])->toBe($enImage['y'])
        ->and($arImage['w'])->toBe($enImage['w'])
        ->and($arImage['h'])->toBe($enImage['h']);

    expect($arDate['x'])->toBe(round(210 - $enDate['x'] - 42.0, 2))
        ->and($arDate['y'])->toBe($enDate['y']);
});

test('reset restores defaults', function () {
    $service = app(BulkDocumentSignaturePlacementService::class);

    $service->save(
        SalaryDeclarationSignaturePlacements::DOCUMENT_TYPE_KEY,
        $service->fromEditorRects(
            signatureLeft: 10,
            signatureTop: 10,
            signatureWidth: 100,
            signatureHeight: 40,
            dateLeft: 10,
            dateTop: 60,
            dateWidth: 80,
            dateHeight: 20,
            canvasWidth: 400,
            canvasHeight: 600,
        ),
    );

    $service->resetToDefaults(SalaryDeclarationSignaturePlacements::DOCUMENT_TYPE_KEY);

    expect(app(SettingService::class)->get(SettingKey::BulkDocumentSignaturePlacementSalaryDeclaration))->toBeNull()
        ->and($service->resolve(SalaryDeclarationSignaturePlacements::DOCUMENT_TYPE_KEY))
        ->toBe(SalaryDeclarationSignaturePlacements::config());
});

test('bulk document type registry resolves saved placement', function () {
    $service = app(BulkDocumentSignaturePlacementService::class);

    $config = $service->fromEditorRects(
        signatureLeft: 20,
        signatureTop: 300,
        signatureWidth: 200,
        signatureHeight: 50,
        dateLeft: 20,
        dateTop: 360,
        dateWidth: 120,
        dateHeight: 24,
        canvasWidth: 600,
        canvasHeight: 900,
    );

    $service->save(SalaryDeclarationSignaturePlacements::DOCUMENT_TYPE_KEY, $config);

    expect(BulkDocumentTypeRegistry::resolveSignaturePlacements('salary_declaration'))
        ->toBe($config);
});
