#!/usr/bin/env php
<?php

/**
 * CI helpers for change classification, Pest file sharding, and quality-gate evaluation.
 * Invoked from .github/workflows/ci.yml and covered by tests/Unit/Ci.
 */

const OMS_CI_PEST_SHARD_COUNT = 6;

const OMS_CI_PEST_TIMINGS_RELATIVE = '.github/ci/pest-timings.json';

/**
 * @param  list<string>  $paths
 * @return array{
 *     pint: bool,
 *     frontend_static: bool,
 *     frontend_build: bool,
 *     pest: bool,
 *     pdf_renderer: bool,
 *     deploy: bool,
 *     docs_only: bool,
 *     scope: string
 * }
 */
function oms_ci_classify_paths(array $paths, bool $detectionFailed = false): array
{
    $full = oms_ci_full_classification();

    if ($detectionFailed || $paths === []) {
        return $full;
    }

    $pint = false;
    $frontendStatic = false;
    $frontendBuild = false;
    $pest = false;
    $pdfRenderer = false;
    $deploy = false;
    $sawApplicationPath = false;
    $forceFullJobs = false;

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

        $sawApplicationPath = true;

        if (oms_ci_is_ci_infra_path($path)) {
            $forceFullJobs = true;
        }

        if (oms_ci_is_pint_path($path)) {
            $pint = true;
        }

        if (oms_ci_is_pest_path($path)) {
            $pest = true;
        }

        if (oms_ci_is_frontend_static_path($path)) {
            $frontendStatic = true;
        }

        if (oms_ci_is_frontend_build_path($path)) {
            $frontendBuild = true;
        }

        if (oms_ci_is_pdf_renderer_path($path)) {
            $pdfRenderer = true;
        }

        if (oms_ci_is_deploy_path($path)) {
            $deploy = true;
        }
    }

    if (! $sawApplicationPath) {
        return [
            'pint' => false,
            'frontend_static' => false,
            'frontend_build' => false,
            'pest' => false,
            'pdf_renderer' => false,
            'deploy' => false,
            'docs_only' => true,
            'scope' => 'docs-only',
        ];
    }

    if ($forceFullJobs) {
        $result = oms_ci_full_classification();
        $result['deploy'] = $deploy;

        return $result;
    }

    $result = [
        'pint' => $pint,
        'frontend_static' => $frontendStatic,
        'frontend_build' => $frontendBuild,
        'pest' => $pest,
        'pdf_renderer' => $pdfRenderer,
        'deploy' => $deploy,
        'docs_only' => false,
        'scope' => '',
    ];
    $result['scope'] = oms_ci_scope_from_flags($result);

    return $result;
}

/**
 * @return array{
 *     pint: bool,
 *     frontend_static: bool,
 *     frontend_build: bool,
 *     pest: bool,
 *     pdf_renderer: bool,
 *     deploy: bool,
 *     docs_only: bool,
 *     scope: string
 * }
 */
function oms_ci_full_classification(): array
{
    return [
        'pint' => true,
        'frontend_static' => true,
        'frontend_build' => true,
        'pest' => true,
        'pdf_renderer' => true,
        'deploy' => true,
        'docs_only' => false,
        'scope' => 'full',
    ];
}

/**
 * @param  array{
 *     pint: bool,
 *     frontend_static: bool,
 *     frontend_build: bool,
 *     pest: bool,
 *     pdf_renderer: bool,
 *     deploy: bool
 * }  $flags
 */
function oms_ci_scope_from_flags(array $flags): string
{
    $pint = $flags['pint'];
    $frontendStatic = $flags['frontend_static'];
    $frontendBuild = $flags['frontend_build'];
    $pest = $flags['pest'];
    $pdf = $flags['pdf_renderer'];
    $deploy = $flags['deploy'];

    if ($pint && $frontendStatic && $frontendBuild && $pest && $pdf && $deploy) {
        return 'full';
    }

    if ($pest && $pint && ! $frontendStatic && ! $frontendBuild && ! $pdf && ! $deploy) {
        return 'tests-only';
    }

    if ($pest && $pint && $deploy && ! $frontendStatic && ! $frontendBuild && ! $pdf) {
        return 'backend-only';
    }

    if ($frontendStatic && $frontendBuild && ! $pint && ! $pest && ! $pdf) {
        return 'frontend-only';
    }

    return 'mixed';
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

function oms_ci_is_ci_infra_path(string $path): bool
{
    return str_starts_with($path, '.github/')
        || in_array($path, [
            'composer.json',
            'composer.lock',
        ], true);
}

function oms_ci_is_pint_path(string $path): bool
{
    return str_ends_with($path, '.php')
        || $path === 'artisan'
        || $path === 'pint.json';
}

function oms_ci_is_pest_path(string $path): bool
{
    foreach ([
        'app/',
        'routes/',
        'database/',
        'config/',
        'tests/',
        'bootstrap/',
        'resources/views/',
    ] as $prefix) {
        if (str_starts_with($path, $prefix)) {
            return true;
        }
    }

    return in_array($path, [
        'artisan',
        'phpunit.xml',
        'composer.json',
        'composer.lock',
        'tests/Pest.php',
        '.env.example',
    ], true);
}

function oms_ci_is_wayfinder_input_path(string $path): bool
{
    return str_starts_with($path, 'routes/')
        || str_starts_with($path, 'app/Http/Controllers/');
}

function oms_ci_is_frontend_static_path(string $path): bool
{
    if (oms_ci_is_wayfinder_input_path($path)) {
        return true;
    }

    foreach ([
        'resources/js/',
        'resources/css/',
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
        'eslint.config.js',
        'scripts/install-puppeteer-browser.mjs',
    ], true)) {
        return true;
    }

    foreach ([
        'vite.config.',
        'eslint.config.',
        '.prettierrc.',
        'tsconfig.',
    ] as $prefix) {
        if (str_starts_with($path, $prefix)) {
            return true;
        }
    }

    return false;
}

function oms_ci_is_frontend_build_path(string $path): bool
{
    if (oms_ci_is_frontend_static_path($path)) {
        return true;
    }

    if (str_starts_with($path, 'resources/cv-templates/')) {
        return true;
    }

    if (str_starts_with($path, 'public/') && ! str_starts_with($path, 'public/hot') && ! str_starts_with($path, 'public/storage')) {
        return true;
    }

    return false;
}

function oms_ci_is_pdf_renderer_path(string $path): bool
{
    if (in_array($path, [
        'package.json',
        'package-lock.json',
        'scripts/install-puppeteer-browser.mjs',
        'config/services.php',
        'app/Services/Documents/PdfOverlayTemplatePdfRenderer.php',
        'app/Services/Documents/ContentTemplatePdfRenderer.php',
        'app/Services/Documents/CustomTemplatePdfRenderer.php',
        'app/Services/SalaryCertificate/SalaryCertificatePdfRenderer.php',
        'app/Services/SalaryDeclaration/SalaryDeclarationPdfRenderer.php',
        'app/Support/Documents/PdfOverlayLayoutPreflight.php',
        'app/Support/Documents/PdfOverlayPlacementValidator.php',
        'app/Support/Documents/DocumentTemplateMergeFields.php',
        'app/Support/Documents/DocumentTemplateLayoutPreflightResult.php',
        'app/Support/BulkDocuments/ConfiguresBrowsershotPdf.php',
        'app/Support/BulkDocuments/ConfiguresBrowsershotEnvironment.php',
        'app/Support/BulkDocuments/BrowsershotEmbeddedFonts.php',
        'app/Support/BulkDocuments/EnsuresBrowsershotChromePermissions.php',
        'app/Support/BulkDocuments/ResolvesBrowsershotBinaries.php',
        'app/Support/BulkDocuments/StampSignedBulkDocumentPdf.php',
        'app/Console/Commands/InstallBrowsershotCommand.php',
        'app/Console/Commands/BrowsershotDoctorCommand.php',
        'resources/views/documents/pdf-overlay-page.blade.php',
        'resources/views/documents/content-template-pdf.blade.php',
        'resources/views/employees/salary-certificate.blade.php',
        'resources/views/employees/salary-declaration.blade.php',
        'tests/Feature/Documents/PdfOverlayTemplatePdfRendererTest.php',
        'tests/Feature/Documents/ContentTemplatePdfRendererTest.php',
        'tests/Feature/Organization/EmployeeSalaryCertificatePrintTest.php',
        'tests/Feature/Organization/EmployeeSalaryDeclarationPrintTest.php',
        'tests/Feature/Organization/DocumentGenerationTemplateLayoutPreflightTest.php',
    ], true)) {
        return true;
    }

    foreach ([
        'app/Support/Documents/PdfOverlay',
        'tests/Unit/Support/Documents/PdfOverlay',
        'tests/Unit/Support/BulkDocuments/ConfiguresBrowsershot',
        'tests/Unit/Support/BulkDocuments/Browsershot',
        'tests/Unit/Support/BulkDocuments/EnsuresBrowsershot',
        'tests/Unit/Support/BulkDocuments/ResolvesBrowsershot',
        'tests/Unit/Support/BulkDocuments/StampSignedBulkDocumentPdf',
    ] as $prefix) {
        if (str_starts_with($path, $prefix)) {
            return true;
        }
    }

    return false;
}

function oms_ci_is_deploy_path(string $path): bool
{
    if (str_starts_with($path, 'tests/')) {
        return false;
    }

    foreach ([
        'app/',
        'routes/',
        'database/',
        'config/',
        'bootstrap/',
        'resources/',
        'public/',
    ] as $prefix) {
        if (str_starts_with($path, $prefix)) {
            return true;
        }
    }

    return in_array($path, [
        'artisan',
        'composer.json',
        'composer.lock',
        'package.json',
        'package-lock.json',
        'scripts/install-puppeteer-browser.mjs',
        '.github/workflows/deploy.yml',
    ], true)
        || str_starts_with($path, 'vite.config.');
}

function oms_ci_is_recognized_path(string $path): bool
{
    return oms_ci_is_docs_path($path)
        || oms_ci_is_ci_infra_path($path)
        || oms_ci_is_pint_path($path)
        || oms_ci_is_pest_path($path)
        || oms_ci_is_frontend_static_path($path)
        || oms_ci_is_frontend_build_path($path)
        || oms_ci_is_pdf_renderer_path($path)
        || oms_ci_is_deploy_path($path)
        || in_array($path, [
            '.editorconfig',
            '.gitattributes',
            '.gitignore',
            '.env.example',
            'phpunit.xml',
            'pint.json',
            'boost.json',
        ], true);
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
 * @return array{default_seconds: float, files: array<string, float>}
 */
function oms_ci_load_pest_timings(string $timingsFile): array
{
    $default = 2.5;
    $files = [];

    if (! is_file($timingsFile)) {
        return ['default_seconds' => $default, 'files' => $files];
    }

    $decoded = json_decode((string) file_get_contents($timingsFile), true);

    if (! is_array($decoded)) {
        return ['default_seconds' => $default, 'files' => $files];
    }

    if (isset($decoded['default_seconds']) && is_numeric($decoded['default_seconds'])) {
        $default = max(0.1, (float) $decoded['default_seconds']);
    }

    $rawFiles = $decoded['files'] ?? [];

    if (is_array($rawFiles)) {
        foreach ($rawFiles as $path => $seconds) {
            if (is_string($path) && is_numeric($seconds)) {
                $files[str_replace('\\', '/', $path)] = max(0.1, (float) $seconds);
            }
        }
    }

    return ['default_seconds' => $default, 'files' => $files];
}

/**
 * Largest Processing Time / greedy bin packing. Same inputs always produce the same shards.
 *
 * @param  list<string>  $files
 * @param  array<string, float>  $weights
 * @return array<int, list<string>>
 */
function oms_ci_pack_pest_shards(array $files, int $total, array $weights, float $defaultWeight): array
{
    $ranked = $files;

    usort($ranked, function (string $left, string $right) use ($weights, $defaultWeight): int {
        $leftWeight = $weights[$left] ?? $defaultWeight;
        $rightWeight = $weights[$right] ?? $defaultWeight;
        $weightCmp = $rightWeight <=> $leftWeight;

        if ($weightCmp !== 0) {
            return $weightCmp;
        }

        return strcmp($left, $right);
    });

    $shards = [];
    $loads = [];

    for ($index = 1; $index <= $total; $index++) {
        $shards[$index] = [];
        $loads[$index] = 0.0;
    }

    foreach ($ranked as $file) {
        $lightest = 1;

        for ($index = 2; $index <= $total; $index++) {
            if ($loads[$index] < $loads[$lightest] - 0.000001) {
                $lightest = $index;
            }
        }

        $shards[$lightest][] = $file;
        $loads[$lightest] += $weights[$file] ?? $defaultWeight;
    }

    foreach ($shards as $index => $shardFiles) {
        sort($shardFiles, SORT_STRING);
        $shards[$index] = $shardFiles;
    }

    return $shards;
}

/**
 * @return list<string>
 */
function oms_ci_pest_shard_files(string $root, int $shard, int $total = OMS_CI_PEST_SHARD_COUNT, ?string $timingsFile = null): array
{
    if ($total < 1 || $shard < 1 || $shard > $total) {
        throw new InvalidArgumentException("Invalid Pest shard {$shard}/{$total}.");
    }

    $files = oms_ci_pest_test_files($root);
    $timingsFile ??= $root.'/'.OMS_CI_PEST_TIMINGS_RELATIVE;
    $timings = oms_ci_load_pest_timings($timingsFile);
    $packed = oms_ci_pack_pest_shards($files, $total, $timings['files'], $timings['default_seconds']);

    return $packed[$shard];
}

/**
 * @return array{ok: bool, default_seconds: float, files: array<string, float>}
 */
function oms_ci_pest_timings_from_junit(string $junitPath): array
{
    if (! is_file($junitPath)) {
        throw new InvalidArgumentException("JUnit file not found: {$junitPath}");
    }

    $xml = simplexml_load_file($junitPath);

    if ($xml === false) {
        throw new InvalidArgumentException("Unable to parse JUnit XML: {$junitPath}");
    }

    $totals = [];

    $register = function (string $file, float $time) use (&$totals): void {
        $normalized = str_replace('\\', '/', explode('::', $file)[0]);
        $marker = '/tests/';
        $position = strpos($normalized, $marker);

        if ($position !== false) {
            $normalized = substr($normalized, $position + 1);
        }

        if (! str_ends_with($normalized, 'Test.php')) {
            return;
        }

        $totals[$normalized] = ($totals[$normalized] ?? 0.0) + $time;
    };

    $walk = function ($node) use (&$walk, $register): void {
        $file = isset($node['file']) ? (string) $node['file'] : '';
        $file = explode('::', $file)[0];

        if ($node->getName() === 'testsuite' && str_ends_with($file, 'Test.php') && isset($node['time'])) {
            $register($file, (float) $node['time']);

            return;
        }

        foreach ($node->testcase ?? [] as $case) {
            if (isset($case['file'])) {
                $register((string) $case['file'], (float) ($case['time'] ?? 0));
            }
        }

        foreach ($node->testsuite ?? [] as $suite) {
            $walk($suite);
        }
    };

    $walk($xml);

    ksort($totals, SORT_STRING);

    $values = array_values($totals);
    sort($values);
    $median = 2.5;

    if ($values !== []) {
        $middle = intdiv(count($values), 2);
        $median = count($values) % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
        $median = max(2.5, round($median, 3));
    }

    $rounded = [];

    foreach ($totals as $file => $seconds) {
        $rounded[$file] = round($seconds, 3);
    }

    return [
        'ok' => true,
        'default_seconds' => $median,
        'files' => $rounded,
    ];
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
        'pdf_renderer' => 'PDF Renderer',
        'pest' => 'Pest',
    ];

    foreach ($jobs as $key => $label) {
        $shouldRun = ($state['run_'.$key] ?? false) === true || ($state['run_'.$key] ?? '') === 'true';
        $result = (string) ($state[$key.'_result'] ?? '');

        if (! $shouldRun && $result === '') {
            $result = 'skipped';
        }

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
        'pest-timings-from-junit' => oms_ci_cli_pest_timings_from_junit($argv),
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
        'pdf_renderer' => oms_ci_bool_string($result['pdf_renderer']),
        'deploy' => oms_ci_bool_string($result['deploy']),
        'docs_only' => oms_ci_bool_string($result['docs_only']),
        'scope' => $result['scope'],
    ]);

    fwrite(STDOUT, sprintf(
        "CI scope=%s pint=%s frontend_static=%s frontend_build=%s pest=%s pdf_renderer=%s deploy=%s docs_only=%s\n",
        $result['scope'],
        oms_ci_bool_string($result['pint']),
        oms_ci_bool_string($result['frontend_static']),
        oms_ci_bool_string($result['frontend_build']),
        oms_ci_bool_string($result['pest']),
        oms_ci_bool_string($result['pdf_renderer']),
        oms_ci_bool_string($result['deploy']),
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
    $timings = oms_ci_cli_option($argv, '--timings');

    try {
        $files = oms_ci_pest_shard_files($root, $shard, $total, $timings);
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

/**
 * @param  list<string>  $argv
 */
function oms_ci_cli_pest_timings_from_junit(array $argv): int
{
    $junit = oms_ci_cli_option($argv, '--junit');
    $output = oms_ci_cli_option($argv, '--output') ?? dirname(__DIR__, 2).'/'.OMS_CI_PEST_TIMINGS_RELATIVE;

    if ($junit === null || $junit === '') {
        fwrite(STDERR, "Missing --junit=path\n");

        return 1;
    }

    try {
        $timings = oms_ci_pest_timings_from_junit($junit);
    } catch (InvalidArgumentException $exception) {
        fwrite(STDERR, $exception->getMessage()."\n");

        return 1;
    }

    $payload = [
        'version' => 1,
        'generated_by' => 'php .github/scripts/ci.php pest-timings-from-junit',
        'default_seconds' => $timings['default_seconds'],
        'files' => $timings['files'],
    ];

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

    if ($json === false) {
        fwrite(STDERR, "Unable to encode timings JSON.\n");

        return 1;
    }

    $directory = dirname($output);

    if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
        fwrite(STDERR, "Unable to create timings directory: {$directory}\n");

        return 1;
    }

    if (file_put_contents($output, $json) === false) {
        fwrite(STDERR, "Unable to write timings file: {$output}\n");

        return 1;
    }

    fwrite(STDOUT, 'Wrote '.count($timings['files'])." file timings to {$output} (default={$timings['default_seconds']}s).\n");

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
        'pdf_renderer_result' => getenv('PDF_RENDERER_RESULT') ?: '',
        'pest_result' => getenv('PEST_RESULT') ?: '',
        'run_pint' => getenv('RUN_PINT') ?: 'false',
        'run_frontend_static' => getenv('RUN_FRONTEND_STATIC') ?: 'false',
        'run_frontend_build' => getenv('RUN_FRONTEND_BUILD') ?: 'false',
        'run_pdf_renderer' => getenv('RUN_PDF_RENDERER') ?: 'false',
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
    fwrite(STDOUT, 'run_pdf_renderer='.(getenv('RUN_PDF_RENDERER') ?: '').' pdf_renderer_result='.(getenv('PDF_RENDERER_RESULT') ?: '')."\n");
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
    fwrite(STDERR, "Usage: php .github/scripts/ci.php classify|pest-shard|pest-shard-total|pest-timings-from-junit|quality-gates\n");

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
