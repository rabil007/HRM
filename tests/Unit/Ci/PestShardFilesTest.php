<?php

require_once dirname(__DIR__, 3).'/.github/scripts/ci.php';

test('every Pest test file belongs to exactly one shard', function () {
    $root = dirname(__DIR__, 3);
    $all = oms_ci_pest_test_files($root);
    $total = OMS_CI_PEST_SHARD_COUNT;

    expect($all)->not->toBeEmpty()
        ->and($total)->toBe(3);

    $union = [];

    foreach (range(1, $total) as $shard) {
        $files = oms_ci_pest_shard_files($root, $shard, $total);

        expect($files)->not->toBeEmpty("Shard {$shard}/{$total} is empty.");

        $union = [...$union, ...$files];
    }

    sort($union, SORT_STRING);

    expect($union)->toEqual($all)
        ->and(array_unique($union))->toHaveCount(count($all));
});

test('shards together equal the phpunit unit and feature suites', function () {
    $root = dirname(__DIR__, 3);
    $discovered = oms_ci_pest_test_files($root);

    expect($discovered)->toContain('tests/Unit/Ci/ClassifyCiScopeTest.php')
        ->and($discovered)->toContain('tests/Feature/DashboardTest.php')
        ->and($discovered)->not->toContain('tests/Pest.php')
        ->and($discovered)->not->toContain('tests/Support/spatie.php');

    foreach ($discovered as $file) {
        expect($file)->toMatch('#^tests/(Unit|Feature)/.+Test\.php$#');
    }
});

test('workflow pest matrix matches the shard constant', function () {
    $workflow = (string) file_get_contents(dirname(__DIR__, 3).'/.github/workflows/ci.yml');

    expect(OMS_CI_PEST_SHARD_COUNT)->toBe(3)
        ->and($workflow)->toContain('PEST_SHARD_TOTAL: '.OMS_CI_PEST_SHARD_COUNT)
        ->and($workflow)->toContain('shard: [1, 2, 3]')
        ->and($workflow)->toContain('--total="${PEST_SHARD_TOTAL}"')
        ->and($workflow)->toContain('php artisan test --compact --ansi "${TEST_FILES[@]}"')
        ->and($workflow)->not->toContain('php artisan test --compact --ansi -- "${TEST_FILES[@]}"');
});

test('deploy downloads the Vite artifact from the triggering CI run and SHA', function () {
    $deploy = (string) file_get_contents(dirname(__DIR__, 3).'/.github/workflows/deploy.yml');

    expect($deploy)->toContain('actions: read')
        ->and($deploy)->toContain('vite-build-${{ github.event.workflow_run.head_sha }}-${{ github.event.workflow_run.id }}-${{ github.event.workflow_run.run_attempt }}')
        ->and($deploy)->toContain('run-id: ${{ github.event.workflow_run.id }}')
        ->and($deploy)->toContain('npm ci --omit=dev')
        ->and($deploy)->toContain('php artisan browsershot:install');
});
