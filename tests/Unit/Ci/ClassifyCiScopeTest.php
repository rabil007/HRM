<?php

require_once dirname(__DIR__, 3).'/.github/scripts/ci.php';

test('docs-only paths skip application jobs and deploy', function () {
    $result = oms_ci_classify_paths([
        'docs/ci.md',
        'docs/document-management.md',
        'README.md',
        '.cursor/rules/project-rules.mdc',
        '.agents/skills/pest-testing/SKILL.md',
        'boost.json',
        '.cursorignore',
    ]);

    expect($result)->toMatchArray([
        'pint' => false,
        'frontend_static' => false,
        'frontend_build' => false,
        'pest' => false,
        'pdf_renderer' => false,
        'deploy' => false,
        'docs_only' => true,
        'scope' => 'docs-only',
    ]);
});

test('php test-only changes run pint and pest without frontend pdf or deploy', function () {
    expect(oms_ci_classify_paths(['tests/Feature/PayrollFooTest.php']))->toMatchArray([
        'pint' => true,
        'pest' => true,
        'frontend_static' => false,
        'frontend_build' => false,
        'pdf_renderer' => false,
        'deploy' => false,
        'docs_only' => false,
        'scope' => 'tests-only',
    ])->and(oms_ci_classify_paths(['tests/Feature/Organization/SomeBackendTest.php']))->toMatchArray([
        'pint' => true,
        'pest' => true,
        'frontend_static' => false,
        'frontend_build' => false,
        'pdf_renderer' => false,
        'deploy' => false,
        'scope' => 'tests-only',
    ]);
});

test('unrelated backend services run pint pest and deploy without frontend or pdf', function () {
    expect(oms_ci_classify_paths([
        'app/Services/Payroll/Foo.php',
        'app/Support/Employees/EmployeeDirectoryQuery.php',
    ]))->toMatchArray([
        'pint' => true,
        'pest' => true,
        'frontend_static' => false,
        'frontend_build' => false,
        'pdf_renderer' => false,
        'deploy' => true,
        'docs_only' => false,
        'scope' => 'backend-only',
    ]);
});

test('routes and controllers trigger wayfinder frontend static and build', function () {
    expect(oms_ci_classify_paths(['routes/web.php']))->toMatchArray([
        'pint' => true,
        'pest' => true,
        'frontend_static' => true,
        'frontend_build' => true,
        'pdf_renderer' => false,
        'deploy' => true,
        'docs_only' => false,
        'scope' => 'mixed',
    ])->and(oms_ci_classify_paths(['app/Http/Controllers/Organization/EmployeeController.php']))->toMatchArray([
        'pint' => true,
        'pest' => true,
        'frontend_static' => true,
        'frontend_build' => true,
        'pdf_renderer' => false,
        'deploy' => true,
        'scope' => 'mixed',
    ]);
});

test('frontend component changes skip pest and pdf', function () {
    expect(oms_ci_classify_paths([
        'resources/js/foo.tsx',
        'resources/js/features/organization/branches/index.tsx',
        'resources/css/app.css',
        'eslint.config.js',
        '.prettierrc',
    ]))->toMatchArray([
        'pint' => false,
        'frontend_static' => true,
        'frontend_build' => true,
        'pest' => false,
        'pdf_renderer' => false,
        'deploy' => true,
        'docs_only' => false,
        'scope' => 'frontend-only',
    ]);
});

test('pdf overlay preflight changes run pest pint pdf and deploy without frontend', function () {
    expect(oms_ci_classify_paths(['app/Support/Documents/PdfOverlayLayoutPreflight.php']))->toMatchArray([
        'pint' => true,
        'pest' => true,
        'frontend_static' => false,
        'frontend_build' => false,
        'pdf_renderer' => true,
        'deploy' => true,
        'docs_only' => false,
        'scope' => 'mixed',
    ]);
});

test('pdf renderer production tests run pdf without frontend or deploy', function () {
    expect(oms_ci_classify_paths(['tests/Feature/Documents/PdfOverlayTemplatePdfRendererTest.php']))->toMatchArray([
        'pint' => true,
        'pest' => true,
        'frontend_static' => false,
        'frontend_build' => false,
        'pdf_renderer' => true,
        'deploy' => false,
        'scope' => 'mixed',
    ]);
});

test('package lock changes run frontend jobs pdf renderer and deploy', function () {
    expect(oms_ci_classify_paths(['package-lock.json']))->toMatchArray([
        'pint' => false,
        'pest' => false,
        'frontend_static' => true,
        'frontend_build' => true,
        'pdf_renderer' => true,
        'deploy' => true,
        'docs_only' => false,
        'scope' => 'mixed',
    ]);
});

test('ci workflow and helper changes fail-safe to full ci', function () {
    $fullJobs = oms_ci_full_classification();
    $fullJobs['deploy'] = false;

    expect(oms_ci_classify_paths(['.github/workflows/ci.yml']))->toMatchArray($fullJobs)
        ->and(oms_ci_classify_paths(['.github/scripts/ci.php']))->toMatchArray($fullJobs)
        ->and(oms_ci_classify_paths(['composer.lock']))->toMatchArray(oms_ci_full_classification());
});

test('unknown paths force full CI', function () {
    expect(oms_ci_classify_paths(['docker-compose.yml']))->toMatchArray(oms_ci_full_classification());
});

test('unknown path mixed with docs still forces full CI', function () {
    expect(oms_ci_classify_paths(['docs/ci.md', 'secret-tooling/foo.sh']))->toMatchArray(oms_ci_full_classification());
});

test('failed or empty change detection fail-safes to full CI', function () {
    expect(oms_ci_classify_paths([], true)['scope'])->toBe('full')
        ->and(oms_ci_classify_paths([])['scope'])->toBe('full');
});

test('classify CLI writes GitHub outputs including deploy', function () {
    $root = dirname(__DIR__, 3);
    $output = tempnam(sys_get_temp_dir(), 'ghout');

    $command = sprintf(
        'printf "docs/ci.md\\nREADME.md\\n" | GITHUB_OUTPUT=%s php %s classify --paths-file=-',
        escapeshellarg($output),
        escapeshellarg($root.'/.github/scripts/ci.php'),
    );

    exec($command, $stdout, $exitCode);

    expect($exitCode)->toBe(0)
        ->and((string) file_get_contents($output))->toContain("docs_only=true\n")
        ->and((string) file_get_contents($output))->toContain("scope=docs-only\n")
        ->and((string) file_get_contents($output))->toContain("pest=false\n")
        ->and((string) file_get_contents($output))->toContain("deploy=false\n");

    unlink($output);
});
