<?php

require_once dirname(__DIR__, 3).'/.github/scripts/ci.php';

test('docs-only paths skip application jobs', function () {
    $result = oms_ci_classify_paths([
        'docs/ci.md',
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
        'docs_only' => true,
        'scope' => 'docs-only',
    ]);
});

test('backend-only paths run pint pest and the vite build', function () {
    $result = oms_ci_classify_paths([
        'app/Support/Employees/EmployeeDirectoryQuery.php',
        'tests/Feature/Organization/EmployeesTest.php',
        'phpunit.xml',
        'pint.json',
    ]);

    expect($result)->toMatchArray([
        'pint' => true,
        'frontend_static' => false,
        'frontend_build' => true,
        'pest' => true,
        'docs_only' => false,
        'scope' => 'backend-only',
    ]);
});

test('frontend-only paths skip pint and pest', function () {
    $result = oms_ci_classify_paths([
        'resources/js/features/organization/branches/index.tsx',
        'resources/css/app.css',
        'eslint.config.js',
        '.prettierrc',
    ]);

    expect($result)->toMatchArray([
        'pint' => false,
        'frontend_static' => true,
        'frontend_build' => true,
        'pest' => false,
        'docs_only' => false,
        'scope' => 'frontend-only',
    ]);
});

test('shared or mixed application paths run full CI', function (array $paths) {
    expect(oms_ci_classify_paths($paths))->toMatchArray([
        'pint' => true,
        'frontend_static' => true,
        'frontend_build' => true,
        'pest' => true,
        'docs_only' => false,
        'scope' => 'full',
    ]);
})->with([
    'routes' => [['routes/web.php']],
    'controllers' => [['app/Http/Controllers/Organization/EmployeeController.php']],
    'form requests' => [['app/Http/Requests/Organization/StoreEmployeeRequest.php']],
    'inertia middleware' => [['app/Http/Middleware/HandleInertiaRequests.php']],
    'composer lock' => [['composer.lock']],
    'package lock' => [['package-lock.json']],
    'config' => [['config/inertia.php']],
    'migrations' => [['database/migrations/2026_01_01_000000_example.php']],
    'inertia pages' => [['resources/js/pages/organization/employees.tsx']],
    'vite config' => [['vite.config.ts']],
    'ci workflow' => [['.github/workflows/ci.yml']],
    'env example' => [['.env.example']],
    'mixed backend and frontend' => [[
        'app/Support/Employees/EmployeeDirectoryQuery.php',
        'resources/js/features/organization/branches/index.tsx',
    ]],
]);

test('unknown paths force full CI', function () {
    expect(oms_ci_classify_paths(['docker-compose.yml']))->toMatchArray([
        'scope' => 'full',
        'pint' => true,
        'frontend_static' => true,
        'frontend_build' => true,
        'pest' => true,
        'docs_only' => false,
    ]);
});

test('unknown path mixed with docs still forces full CI', function () {
    expect(oms_ci_classify_paths(['docs/ci.md', 'secret-tooling/foo.sh']))->toMatchArray([
        'scope' => 'full',
        'docs_only' => false,
        'pest' => true,
        'frontend_static' => true,
    ]);
});

test('failed or empty change detection fail-safes to full CI', function () {
    expect(oms_ci_classify_paths([], true)['scope'])->toBe('full')
        ->and(oms_ci_classify_paths([])['scope'])->toBe('full');
});

test('classify CLI writes GitHub outputs for docs-only paths', function () {
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
        ->and((string) file_get_contents($output))->toContain("pest=false\n");

    unlink($output);
});
