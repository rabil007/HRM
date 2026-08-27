#!/usr/bin/env php
<?php

/**
 * CI helpers for change classification, Pest file sharding, and quality-gate evaluation.
 * Invoked from .github/workflows/ci.yml and covered by tests/Unit/Ci.
 */

const OMS_CI_PEST_SHARD_COUNT = 6;

/**
 * @param  list<string>  $paths
 * @return array{
 *     pint: bool,
 *     frontend_static: bool,
 *     frontend_build: bool,
 *     pest: bool,
 *     docs_only: bool,
 *     scope: string
 * }
 */
function oms_ci_classify_paths(array $paths, bool $detectionFailed = false): array
{
    $full = [
        'pint' => true,
        'frontend_static' => true,
        'frontend_build' => true,
        'pest' => true,
        'docs_only' => false,
        'scope' => 'full',
    ];

    if ($detectionFailed || $paths === []) {
        return $full;
    }

    $hasBackend = false;
    $hasFrontend = false;
    $hasShared = false;

    foreach ($paths as $path) {
        $path = str_replace('\\', '/', trim($path));

        if ($path === '') {
            continue;
        }

        if (oms_ci_is_docs_path($path)) {
            continue;
        }

        if (! oms_ci_is_recognized_path($path)) {
            return $full;
        }

        if (oms_ci_is_shared_path($path)) {
            $hasShared = true;
            $hasBackend = true;
            $hasFrontend = true;

            continue;
        }

        if (oms_ci_is_backend_path($path)) {
            $hasBackend = true;

            continue;
        }

        if (oms_ci_is_frontend_path($path)) {
            $hasFrontend = true;

            continue;
        }

        return $full;
    }

    if (! $hasBackend && ! $hasFrontend && ! $hasShared) {
        return [
            'pint' => false,
            'frontend_static' => false,
            'frontend_build' => false,
            'pest' => false,
            'docs_only' => true,
            'scope' => 'docs-only',
        ];
    }

    if ($hasShared || ($hasBackend && $hasFrontend)) {
        return $full;
    }

    if ($hasBackend) {
        return [
            'pint' => true,
            'frontend_static' => false,
            'frontend_build' => true,
            'pest' => true,
            'docs_only' => false,
            'scope' => 'backend-only',
        ];
    }

    return [
        'pint' => false,
        'frontend_static' => true,
        'frontend_build' => true,
        'pest' => false,
        'docs_only' => false,
        'scope' => 'frontend-only',
    ];
}

function oms_ci_is_docs_path(string $path): bool
{
    foreach ([
        'docs/',
        '.cursor/',
        '.agents/',
        '.gemini/',
    ] as $prefix) {
        if (str_starts_with($path, $prefix)) {
            return true;
        }
    }

    return in_array($path, [
        '.cursorignore',
        '.cursorindexingignore',
        'boost.json',
    ], true)
        || (str_ends_with($path, '.md') && ! str_contains($path, '/'));
}

function oms_ci_is_shared_path(string $path): bool
{
    foreach ([
        'routes/',
        'app/Http/',
        'app/Providers/',
        'app/View/',
        'bootstrap/',
        'config/',
        'database/',
        '.github/',
        'public/',
        'resources/views/',
        'resources/cv-templates/',
        'resources/js/pages/',
    ] as $prefix) {
        if (str_starts_with($path, $prefix)) {
            return true;
        }
    }

    if (in_array($path, [
        'artisan',
        'composer.json',
        'composer.lock',
        'package.json',
        'package-lock.json',
        'tsconfig.json',
        '.env.example',
        'resources/js/app.tsx',
        'resources/js/ssr.tsx',
        '.editorconfig',
        '.gitattributes',
        '.gitignore',
    ], true)) {
        return true;
    }

    return str_starts_with($path, 'vite.config.');
}

function oms_ci_is_backend_path(string $path): bool
{
    foreach (['app/', 'tests/', 'database/'] as $prefix) {
        if (str_starts_with($path, $prefix)) {
            return true;
        }
    }

    return in_array($path, [
        'artisan',
        'phpunit.xml',
        'pint.json',
        'composer.json',
        'composer.lock',
    ], true)
        || str_ends_with($path, '.php');
}

function oms_ci_is_frontend_path(string $path): bool
{
    foreach ([
        'resources/js/',
        'resources/css/',
        'resources/views/',
        'resources/cv-templates/',
    ] as $prefix) {
        if (str_starts_with($path, $prefix)) {
            return true;
        }
    }

    if (in_array($path, [
        'package.json',
        'package-lock.json',
        'tsconfig.json',
        'components.json',
        '.npmrc',
        '.prettierrc',
        '.prettierignore',
    ], true)) {
        return true;
    }

    foreach ([
        'vite.config.',
        'eslint.config.',
        '.prettierrc.',
    ] as $prefix) {
        if (str_starts_with($path, $prefix)) {
            return true;
        }
    }

    foreach (['.js', '.jsx', '.mjs', '.cjs', '.ts', '.tsx', '.css', '.scss'] as $suffix) {
        if (str_ends_with($path, $suffix)) {
            return true;
        }
    }

    return false;
}

function oms_ci_is_recognized_path(string $path): bool
{
    return oms_ci_is_docs_path($path)
        || oms_ci_is_shared_path($path)
        || oms_ci_is_backend_path($path)
        || oms_ci_is_frontend_path($path);
}

/**
 * @return list<string>
 */
function oms_ci_pest_test_files(string $root): array
{
    $files = [];

    foreach (['tests/Unit', 'tests/Feature'] as $suite) {
        $directory = $root.'/'.$suite;

        if (! is_dir($directory)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
                $relative = substr($file->getPathname(), strlen($root) + 1);
                $files[] = str_replace('\\', '/', $relative);
            }
        }
    }

    sort($files, SORT_STRING);

    return $files;
}

/**
 * @return list<string>
 */
function oms_ci_pest_shard_files(string $root, int $shard, int $total = OMS_CI_PEST_SHARD_COUNT): array
{
    if ($total < 1 || $shard < 1 || $shard > $total) {
        throw new InvalidArgumentException("Invalid Pest shard {$shard}/{$total}.");
    }

    $files = [];

    foreach (oms_ci_pest_test_files($root) as $index => $file) {
        if (($index % $total) + 1 === $shard) {
            $files[] = $file;
        }
    }

    return $files;
}

/**
 * @param  array<string, mixed>  $state
 * @return array{ok: bool, errors: list<string>}
 */
function oms_ci_evaluate_quality_gates(array $state): array
{
    $errors = [];

    $changes = (string) ($state['changes_result'] ?? '');

    if ($changes !== 'success') {
        $errors[] = "Change detection failed; refusing to pass the CI gate (result={$changes}).";

        return ['ok' => false, 'errors' => $errors];
    }

    $jobs = [
        'pint' => 'PHP Style (Pint)',
        'frontend_static' => 'Frontend Static',
        'frontend_build' => 'Frontend Build',
        'pest' => 'Pest',
    ];

    foreach ($jobs as $key => $label) {
        $shouldRun = ($state['run_'.$key] ?? false) === true || ($state['run_'.$key] ?? '') === 'true';
        $result = (string) ($state[$key.'_result'] ?? '');
        $error = oms_ci_assert_job_result($label, $result, $shouldRun);

        if ($error !== null) {
            $errors[] = $error;
        }
    }

    $runPest = ($state['run_pest'] ?? false) === true || ($state['run_pest'] ?? '') === 'true';
    $pestResult = (string) ($state['pest_result'] ?? '');
    $expectedShards = (int) ($state['expected_pest_shards'] ?? OMS_CI_PEST_SHARD_COUNT);
    $buildPresent = $state['vite_build_present'] ?? null;
    $viteBuildOk = $buildPresent === true || $buildPresent === 'true';

    if ($runPest && $pestResult === 'success') {
        $foundShards = $state['found_pest_shards'] ?? [];

        if (! is_array($foundShards)) {
            $foundShards = [];
        }

        $found = array_values(array_unique(array_map('intval', $foundShards)));
        sort($found);

        $expected = range(1, $expectedShards);

        if ($found !== $expected) {
            $errors[] = 'Pest shard results are missing or incomplete (found=['.implode(',', $found)."], expected=1..{$expectedShards}).";
        }
    }

    $runBuild = ($state['run_frontend_build'] ?? false) === true || ($state['run_frontend_build'] ?? '') === 'true';
    $buildResult = (string) ($state['frontend_build_result'] ?? '');

    if ($runBuild && $buildResult === 'success' && ! $viteBuildOk) {
        $errors[] = 'Frontend build succeeded but the Vite artifact was missing.';
    }

    return [
        'ok' => $errors === [],
        'errors' => $errors,
    ];
}

function oms_ci_assert_job_result(string $label, string $result, bool $shouldRun): ?string
{
    if ($shouldRun) {
        if ($result === 'success') {
            return null;
        }

        return "{$label} did not succeed (result={$result}).";
    }

    if ($result === 'skipped') {
        return null;
    }

    return "{$label} was expected to be skipped (result={$result}).";
}

/**
 * @param  list<string>  $argv
 */
function oms_ci_main(array $argv): int
{
    $command = $argv[1] ?? '';

    return match ($command) {
        'classify' => oms_ci_cli_classify($argv),
        'pest-shard' => oms_ci_cli_pest_shard($argv),
        'pest-shard-total' => oms_ci_cli_print(OMS_CI_PEST_SHARD_COUNT),
        'quality-gates' => oms_ci_cli_quality_gates(),
        default => oms_ci_cli_usage($command),
    };
}

/**
 * @param  list<string>  $argv
 */
function oms_ci_cli_classify(array $argv): int
{
    $detectionFailed = in_array('--fail-safe', $argv, true);
    $paths = [];

    if (! $detectionFailed) {
        $pathsFile = oms_ci_cli_option($argv, '--paths-file') ?? '-';

        if ($pathsFile === '-') {
            $stdin = stream_get_contents(STDIN);
            $paths = ($stdin === false || $stdin === '')
                ? []
                : (preg_split('/\r\n|\r|\n/', rtrim($stdin, "\r\n")) ?: []);
        } else {
            $contents = file_get_contents($pathsFile);

            if ($contents === false) {
                fwrite(STDERR, "Unable to read paths file: {$pathsFile}\n");

                return 1;
            }

            $paths = preg_split('/\r\n|\r|\n/', rtrim($contents, "\r\n")) ?: [];
        }
    }

    $result = oms_ci_classify_paths(
        array_values(array_filter($paths, fn (string $path): bool => $path !== '')),
        $detectionFailed,
    );

    oms_ci_emit_outputs([
        'pint' => oms_ci_bool_string($result['pint']),
        'frontend_static' => oms_ci_bool_string($result['frontend_static']),
        'frontend_build' => oms_ci_bool_string($result['frontend_build']),
        'pest' => oms_ci_bool_string($result['pest']),
        'docs_only' => oms_ci_bool_string($result['docs_only']),
        'scope' => $result['scope'],
    ]);

    fwrite(STDOUT, sprintf(
        "CI scope=%s pint=%s frontend_static=%s frontend_build=%s pest=%s docs_only=%s\n",
        $result['scope'],
        oms_ci_bool_string($result['pint']),
        oms_ci_bool_string($result['frontend_static']),
        oms_ci_bool_string($result['frontend_build']),
        oms_ci_bool_string($result['pest']),
        oms_ci_bool_string($result['docs_only']),
    ));

    return 0;
}

/**
 * @param  list<string>  $argv
 */
function oms_ci_cli_pest_shard(array $argv): int
{
    $shard = (int) (oms_ci_cli_option($argv, '--shard') ?? 0);
    $total = (int) (oms_ci_cli_option($argv, '--total') ?? OMS_CI_PEST_SHARD_COUNT);
    $root = oms_ci_cli_option($argv, '--root') ?? dirname(__DIR__, 2);

    try {
        $files = oms_ci_pest_shard_files($root, $shard, $total);
    } catch (InvalidArgumentException $exception) {
        fwrite(STDERR, $exception->getMessage()."\n");

        return 1;
    }

    if ($files === []) {
        fwrite(STDERR, "Pest shard {$shard}/{$total} produced no test files.\n");

        return 1;
    }

    fwrite(STDOUT, implode("\n", $files)."\n");

    return 0;
}

function oms_ci_cli_quality_gates(): int
{
    $runPest = getenv('RUN_PEST') ?: 'false';
    $pestResult = getenv('PEST_RESULT') ?: '';
    $shardList = null;

    if ($runPest === 'true' && $pestResult === 'success') {
        $foundShards = getenv('FOUND_PEST_SHARDS');
        $shardList = is_string($foundShards) && $foundShards !== ''
            ? array_map('intval', explode(',', $foundShards))
            : [];
    }

    $evaluation = oms_ci_evaluate_quality_gates([
        'changes_result' => getenv('CHANGES_RESULT') ?: '',
        'pint_result' => getenv('PINT_RESULT') ?: '',
        'frontend_static_result' => getenv('FRONTEND_STATIC_RESULT') ?: '',
        'frontend_build_result' => getenv('FRONTEND_BUILD_RESULT') ?: '',
        'pest_result' => getenv('PEST_RESULT') ?: '',
        'run_pint' => getenv('RUN_PINT') ?: 'false',
        'run_frontend_static' => getenv('RUN_FRONTEND_STATIC') ?: 'false',
        'run_frontend_build' => getenv('RUN_FRONTEND_BUILD') ?: 'false',
        'run_pest' => getenv('RUN_PEST') ?: 'false',
        'docs_only' => getenv('DOCS_ONLY') ?: 'false',
        'scope' => getenv('SCOPE') ?: '',
        'expected_pest_shards' => getenv('EXPECTED_PEST_SHARDS') ?: (string) OMS_CI_PEST_SHARD_COUNT,
        'found_pest_shards' => $shardList,
        'vite_build_present' => getenv('VITE_BUILD_PRESENT') ?: null,
    ]);

    $scope = getenv('SCOPE') ?: 'unknown';
    $docsOnly = getenv('DOCS_ONLY') ?: 'false';

    fwrite(STDOUT, "scope={$scope}\n");
    fwrite(STDOUT, "docs_only={$docsOnly}\n");
    fwrite(STDOUT, 'run_pint='.(getenv('RUN_PINT') ?: '').' pint_result='.(getenv('PINT_RESULT') ?: '')."\n");
    fwrite(STDOUT, 'run_frontend_static='.(getenv('RUN_FRONTEND_STATIC') ?: '').' frontend_static_result='.(getenv('FRONTEND_STATIC_RESULT') ?: '')."\n");
    fwrite(STDOUT, 'run_frontend_build='.(getenv('RUN_FRONTEND_BUILD') ?: '').' frontend_build_result='.(getenv('FRONTEND_BUILD_RESULT') ?: '')."\n");
    fwrite(STDOUT, 'run_pest='.(getenv('RUN_PEST') ?: '').' pest_result='.(getenv('PEST_RESULT') ?: '')."\n");
    fwrite(STDOUT, 'changes_result='.(getenv('CHANGES_RESULT') ?: '')."\n");

    if (! $evaluation['ok']) {
        foreach ($evaluation['errors'] as $error) {
            fwrite(STDERR, "::error::{$error}\n");
        }

        return 1;
    }

    if ($docsOnly === 'true') {
        fwrite(STDOUT, "Docs-only changes detected; application install/test jobs were skipped.\n");
    }

    fwrite(STDOUT, "CI gate passed (scope={$scope}).\n");

    return 0;
}

function oms_ci_cli_print(int|string $value): int
{
    fwrite(STDOUT, $value."\n");

    return 0;
}

function oms_ci_cli_usage(string $command): int
{
    fwrite(STDERR, "Unknown CI command: {$command}\n");
    fwrite(STDERR, "Usage: php .github/scripts/ci.php classify|pest-shard|pest-shard-total|quality-gates\n");

    return 1;
}

/**
 * @param  list<string>  $argv
 */
function oms_ci_cli_option(array $argv, string $name): ?string
{
    foreach ($argv as $argument) {
        if (str_starts_with($argument, $name.'=')) {
            return substr($argument, strlen($name) + 1);
        }
    }

    return null;
}

/**
 * @param  array<string, string>  $outputs
 */
function oms_ci_emit_outputs(array $outputs): void
{
    $githubOutput = getenv('GITHUB_OUTPUT');

    if (! is_string($githubOutput) || $githubOutput === '') {
        return;
    }

    $handle = fopen($githubOutput, 'a');

    if ($handle === false) {
        throw new RuntimeException('Unable to write GitHub Actions outputs.');
    }

    foreach ($outputs as $key => $value) {
        fwrite($handle, "{$key}={$value}\n");
    }

    fclose($handle);
}

function oms_ci_bool_string(bool $value): string
{
    return $value ? 'true' : 'false';
}

if (realpath($argv[0] ?? '') === realpath(__FILE__)) {
    exit(oms_ci_main($argv));
}
