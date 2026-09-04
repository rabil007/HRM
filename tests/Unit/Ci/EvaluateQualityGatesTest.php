<?php

require_once dirname(__DIR__, 3).'/.github/scripts/ci.php';

test('quality gates pass for docs-only expected skips', function () {
    $result = oms_ci_evaluate_quality_gates([
        'changes_result' => 'success',
        'pint_result' => 'skipped',
        'frontend_static_result' => 'skipped',
        'frontend_build_result' => 'skipped',
        'pdf_renderer_result' => 'skipped',
        'pest_result' => 'skipped',
        'run_pint' => false,
        'run_frontend_static' => false,
        'run_frontend_build' => false,
        'run_pdf_renderer' => false,
        'run_pest' => false,
        'docs_only' => true,
        'scope' => 'docs-only',
    ]);

    expect($result['ok'])->toBeTrue()
        ->and($result['errors'])->toBeEmpty();
});

test('quality gates pass when every required job succeeds', function () {
    $result = oms_ci_evaluate_quality_gates([
        'changes_result' => 'success',
        'pint_result' => 'success',
        'frontend_static_result' => 'success',
        'frontend_build_result' => 'success',
        'pdf_renderer_result' => 'success',
        'pest_result' => 'success',
        'run_pint' => true,
        'run_frontend_static' => true,
        'run_frontend_build' => true,
        'run_pdf_renderer' => true,
        'run_pest' => true,
        'expected_pest_shards' => 6,
        'found_pest_shards' => [1, 2, 3, 4, 5, 6],
        'vite_build_present' => true,
        'scope' => 'full',
    ]);

    expect($result['ok'])->toBeTrue();
});

test('quality gates fail when a required job fails', function () {
    $result = oms_ci_evaluate_quality_gates([
        'changes_result' => 'success',
        'pint_result' => 'success',
        'frontend_static_result' => 'success',
        'frontend_build_result' => 'success',
        'pest_result' => 'failure',
        'run_pint' => true,
        'run_frontend_static' => true,
        'run_frontend_build' => true,
        'run_pest' => true,
        'vite_build_present' => true,
    ]);

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'])->toContain('Pest did not succeed (result=failure).');
});

test('quality gates fail when frontend static fails', function () {
    $result = oms_ci_evaluate_quality_gates([
        'changes_result' => 'success',
        'pint_result' => 'success',
        'frontend_static_result' => 'failure',
        'frontend_build_result' => 'success',
        'pest_result' => 'success',
        'run_pint' => true,
        'run_frontend_static' => true,
        'run_frontend_build' => true,
        'run_pest' => true,
        'expected_pest_shards' => 6,
        'found_pest_shards' => [1, 2, 3, 4, 5, 6],
        'vite_build_present' => true,
    ]);

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'])->toContain('Frontend Static did not succeed (result=failure).');
});

test('quality gates fail when a required job is cancelled', function () {
    $result = oms_ci_evaluate_quality_gates([
        'changes_result' => 'success',
        'pint_result' => 'cancelled',
        'frontend_static_result' => 'success',
        'frontend_build_result' => 'success',
        'pest_result' => 'success',
        'run_pint' => true,
        'run_frontend_static' => true,
        'run_frontend_build' => true,
        'run_pest' => true,
        'found_pest_shards' => [1, 2, 3, 4, 5, 6],
        'vite_build_present' => true,
    ]);

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'][0])->toContain('PHP Style (Pint) did not succeed (result=cancelled).');
});

test('quality gates fail when a required job does not run', function () {
    $result = oms_ci_evaluate_quality_gates([
        'changes_result' => 'success',
        'pint_result' => 'skipped',
        'frontend_static_result' => 'success',
        'frontend_build_result' => 'success',
        'pest_result' => 'success',
        'run_pint' => true,
        'run_frontend_static' => true,
        'run_frontend_build' => true,
        'run_pest' => true,
        'found_pest_shards' => [1, 2, 3, 4, 5, 6],
        'vite_build_present' => true,
    ]);

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'][0])->toContain('PHP Style (Pint) did not succeed (result=skipped).');
});

test('quality gates fail when pest shards are missing', function () {
    $result = oms_ci_evaluate_quality_gates([
        'changes_result' => 'success',
        'pint_result' => 'success',
        'frontend_static_result' => 'success',
        'frontend_build_result' => 'success',
        'pest_result' => 'success',
        'run_pint' => true,
        'run_frontend_static' => true,
        'run_frontend_build' => true,
        'run_pest' => true,
        'expected_pest_shards' => 6,
        'found_pest_shards' => [1, 2, 3, 4, 5],
        'vite_build_present' => true,
    ]);

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'][0])->toContain('Pest shard results are missing');
});

test('quality gates fail when the vite build artifact is missing', function () {
    $result = oms_ci_evaluate_quality_gates([
        'changes_result' => 'success',
        'pint_result' => 'skipped',
        'frontend_static_result' => 'success',
        'frontend_build_result' => 'success',
        'pest_result' => 'skipped',
        'run_pint' => false,
        'run_frontend_static' => true,
        'run_frontend_build' => true,
        'run_pest' => false,
        'vite_build_present' => false,
        'scope' => 'frontend-only',
    ]);

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'][0])->toContain('Vite artifact was missing');
});

test('quality gates fail when change detection fails', function () {
    $result = oms_ci_evaluate_quality_gates([
        'changes_result' => 'failure',
        'pint_result' => 'skipped',
        'frontend_static_result' => 'skipped',
        'frontend_build_result' => 'skipped',
        'pest_result' => 'skipped',
        'run_pint' => false,
        'run_frontend_static' => false,
        'run_frontend_build' => false,
        'run_pest' => false,
    ]);

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'][0])->toContain('Change detection failed');
});

test('quality gates fail when pdf renderer fails', function () {
    $result = oms_ci_evaluate_quality_gates([
        'changes_result' => 'success',
        'pint_result' => 'success',
        'frontend_static_result' => 'success',
        'frontend_build_result' => 'success',
        'pdf_renderer_result' => 'failure',
        'pest_result' => 'success',
        'run_pint' => true,
        'run_frontend_static' => true,
        'run_frontend_build' => true,
        'run_pdf_renderer' => true,
        'run_pest' => true,
        'expected_pest_shards' => 6,
        'found_pest_shards' => [1, 2, 3, 4, 5, 6],
        'vite_build_present' => true,
    ]);

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'])->toContain('PDF Renderer did not succeed (result=failure).');
});

test('backend-only expected skips still pass the aggregator', function () {
    $result = oms_ci_evaluate_quality_gates([
        'changes_result' => 'success',
        'pint_result' => 'success',
        'frontend_static_result' => 'skipped',
        'frontend_build_result' => 'skipped',
        'pdf_renderer_result' => 'skipped',
        'pest_result' => 'success',
        'run_pint' => true,
        'run_frontend_static' => false,
        'run_frontend_build' => false,
        'run_pdf_renderer' => false,
        'run_pest' => true,
        'expected_pest_shards' => 6,
        'found_pest_shards' => [1, 2, 3, 4, 5, 6],
        'scope' => 'backend-only',
    ]);

    expect($result['ok'])->toBeTrue();
});

test('quality gates fail when a required pdf renderer job fails for a pdf change', function () {
    $result = oms_ci_evaluate_quality_gates([
        'changes_result' => 'success',
        'pint_result' => 'success',
        'frontend_static_result' => 'skipped',
        'frontend_build_result' => 'skipped',
        'pdf_renderer_result' => 'failure',
        'pest_result' => 'success',
        'run_pint' => true,
        'run_frontend_static' => false,
        'run_frontend_build' => false,
        'run_pdf_renderer' => true,
        'run_pest' => true,
        'expected_pest_shards' => 6,
        'found_pest_shards' => [1, 2, 3, 4, 5, 6],
    ]);

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'])->toContain('PDF Renderer did not succeed (result=failure).');
});
