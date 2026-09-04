<?php

require_once dirname(__DIR__, 3).'/.github/scripts/ci.php';

test('every Pest test file belongs to exactly one shard', function () {
    $root = dirname(__DIR__, 3);
    $all = oms_ci_pest_test_files($root);
    $total = OMS_CI_PEST_SHARD_COUNT;

    expect($all)->not->toBeEmpty()
        ->and($total)->toBe(6);

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

    expect(OMS_CI_PEST_SHARD_COUNT)->toBe(6)
        ->and($workflow)->toContain('PEST_SHARD_TOTAL: '.OMS_CI_PEST_SHARD_COUNT)
        ->and($workflow)->toContain('shard: [1, 2, 3, 4, 5, 6]')
        ->and($workflow)->toContain('--total="${PEST_SHARD_TOTAL}"')
        ->and($workflow)->toContain('php artisan test --compact --ansi "${TEST_FILES[@]}"')
        ->and($workflow)->not->toContain('php artisan test --compact --ansi -- "${TEST_FILES[@]}"')
        ->and($workflow)->not->toContain('--optimize-autoloader');
});

test('frontend build lets the Vite Wayfinder plugin generate types', function () {
    $workflow = (string) file_get_contents(dirname(__DIR__, 3).'/.github/workflows/ci.yml');
    $frontendBuild = strstr($workflow, 'name: Frontend Build');
    $frontendBuild = strstr($frontendBuild, 'name: PDF Renderer', true) ?: $frontendBuild;

    expect($frontendBuild)->toContain('npm run build')
        ->and($frontendBuild)->not->toContain('php artisan wayfinder:generate');
});

test('node_modules cache is exact-lockfile keyed without restore-keys', function () {
    $action = (string) file_get_contents(dirname(__DIR__, 3).'/.github/actions/node-modules/action.yml');

    expect($action)->toContain("key: node-modules-\${{ runner.os }}-\${{ runner.arch }}-node\${{ env.NODE_VERSION }}-\${{ hashFiles('package-lock.json') }}")
        ->and($action)->not->toContain('restore-keys:');
});

test('weighted packing assigns every file once and is deterministic', function () {
    $files = [
        'tests/Feature/HeavyTest.php',
        'tests/Feature/MediumTest.php',
        'tests/Feature/LightATest.php',
        'tests/Feature/LightBTest.php',
        'tests/Feature/LightCTest.php',
        'tests/Feature/LightDTest.php',
    ];
    $weights = [
        'tests/Feature/HeavyTest.php' => 80.0,
        'tests/Feature/MediumTest.php' => 40.0,
        'tests/Feature/LightATest.php' => 5.0,
        'tests/Feature/LightBTest.php' => 5.0,
        'tests/Feature/LightCTest.php' => 5.0,
        'tests/Feature/LightDTest.php' => 5.0,
    ];

    $first = oms_ci_pack_pest_shards($files, 3, $weights, 2.5);
    $second = oms_ci_pack_pest_shards($files, 3, $weights, 2.5);

    expect($first)->toEqual($second);

    $union = [...$first[1], ...$first[2], ...$first[3]];
    sort($union, SORT_STRING);
    $expected = $files;
    sort($expected, SORT_STRING);

    expect($union)->toEqual($expected)
        ->and($first[1])->toContain('tests/Feature/HeavyTest.php')
        ->and($first[2])->toContain('tests/Feature/MediumTest.php')
        ->and($first[1])->not->toContain('tests/Feature/MediumTest.php');
});

test('deploy uses the CI plan and never rebuilds an unvalidated frontend', function () {
    $deploy = (string) file_get_contents(dirname(__DIR__, 3).'/.github/workflows/deploy.yml');

    expect($deploy)->toContain('actions: read')
        ->and($deploy)->toContain('ci-plan-${{ github.event.workflow_run.head_sha }}-${{ github.event.workflow_run.id }}-${{ github.event.workflow_run.run_attempt }}')
        ->and($deploy)->toContain('vite-build-${{ github.event.workflow_run.head_sha }}-${{ github.event.workflow_run.id }}-${{ github.event.workflow_run.run_attempt }}')
        ->and($deploy)->toContain('run-id: ${{ github.event.workflow_run.id }}')
        ->and($deploy)->toContain('No deployable application changes.')
        ->and($deploy)->toContain('npm ci --omit=dev --no-audit --no-fund')
        ->and($deploy)->toContain('php artisan browsershot:install')
        ->and($deploy)->toContain('STAMP_FILE="$STAMP_DIR/npm-lock.sha256"')
        ->and($deploy)->not->toContain('CI Vite artifact missing or SHA mismatch; rebuilding')
        ->and($deploy)->toContain('cancel-in-progress: false')
        ->and($deploy)->toContain('git reset --hard "$TARGET_SHA"');
});
