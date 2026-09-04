<?php

use App\Support\Documents\Signing\SignatureImageBoxFit;

test('defaults to centering a contained signature inside the box', function () {
    [$drawW, $drawH, $drawX, $drawY] = SignatureImageBoxFit::contained(
        boxX: 10,
        boxY: 20,
        boxW: 100,
        boxH: 40,
        imgW: 40,
        imgH: 20,
    );

    expect($drawW)->toBe(80.0)
        ->and($drawH)->toBe(40.0)
        ->and($drawX)->toBe(20.0)
        ->and($drawY)->toBe(20.0);
});

test('positions a contained signature using left and baseline alignment', function () {
    [$drawW, $drawH, $drawX, $drawY] = SignatureImageBoxFit::contained(
        boxX: 10,
        boxY: 20,
        boxW: 100,
        boxH: 50,
        imgW: 80,
        imgH: 20,
        horizontalAlign: 'left',
        verticalAlign: 'baseline',
    );

    expect($drawW)->toBe(100.0)
        ->and($drawH)->toBe(25.0)
        ->and($drawX)->toBe(10.0)
        ->and($drawY)->toBe(45.0);
});

test('positions a contained signature using right and top alignment', function () {
    [$drawW, $drawH, $drawX, $drawY] = SignatureImageBoxFit::contained(
        boxX: 10,
        boxY: 20,
        boxW: 100,
        boxH: 40,
        imgW: 40,
        imgH: 20,
        horizontalAlign: 'right',
        verticalAlign: 'top',
    );

    expect($drawW)->toBe(80.0)
        ->and($drawH)->toBe(40.0)
        ->and($drawX)->toBe(30.0)
        ->and($drawY)->toBe(20.0);
});
