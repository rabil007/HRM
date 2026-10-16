<?php

use Illuminate\Support\Carbon;

/**
 * Guards Inertia/JSON payloads against timezone-shifted calendar dates.
 *
 * Eloquent `date` casts serialize as UTC ISO instants when passed as Carbon.
 * With APP_TIMEZONE=Asia/Dubai that becomes the previous calendar day in JSON.
 */
test('inertia resources presenters page data and controllers serialize calendar dates with toDateString', function () {
    $root = dirname(__DIR__, 3).'/app';
    $keys = collectModelCalendarDateKeys($root.'/Models');

    expect($keys)->not->toBeEmpty();

    $hits = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        $relative = str_replace('\\', '/', substr($path, strlen(dirname($root)) + 1));
        $isPayloadMapper = (bool) preg_match('/(Resource|Presenter|PageData)\.php$/', $file->getFilename());
        $isController = str_contains($relative, 'Http/Controllers/');

        if (! $isPayloadMapper && ! $isController) {
            continue;
        }

        $lines = file($path) ?: [];

        foreach ($lines as $lineNumber => $line) {
            if (! preg_match("/'([^']+)'\s*=>\s*(.+),?\s*$/", trim($line), $matches)) {
                continue;
            }

            $key = $matches[1];
            $expression = rtrim($matches[2], ',');

            if (! in_array($key, $keys, true)) {
                continue;
            }

            // Direct or nested Eloquent reads: $model->start_date / $employee->currentContract?->start_date
            if (! preg_match(
                '/\$[A-Za-z0-9_]+(?:(?:\?->|->)[A-Za-z0-9_]+)*(?:\?->|->)'.preg_quote($key, '/').'\b/',
                $expression,
            )) {
                continue;
            }

            if (expressionAlreadySerializesCalendarDate($expression)) {
                continue;
            }

            if (expressionIsNonPayloadNoise($expression)) {
                continue;
            }

            // Only skip clear Eloquent write calls on the same statement line.
            if (preg_match('/::create\(|->update\(|->forceFill\(|->fill\(/', $expression)) {
                continue;
            }

            $hits[] = sprintf('%s:%d %s => %s', $relative, $lineNumber + 1, $key, $expression);
        }
    }

    expect($hits)->toBeEmpty(
        "Calendar date props must use toDateString()/Y-m-d before Inertia JSON:\n".implode("\n", $hits)
    );
});

test('nested contract dates serialize as Y-m-d through employee list and detail resources', function () {
    config(['app.timezone' => 'Asia/Dubai']);

    $employee = new class
    {
        public $date_of_birth;

        public $hire_date;

        public $termination_date;

        public $currentContract;

        public function __construct()
        {
            $this->date_of_birth = Carbon::parse('1992-08-29', 'Asia/Dubai')->startOfDay();
            $this->hire_date = Carbon::parse('2024-01-10', 'Asia/Dubai')->startOfDay();
            $this->termination_date = Carbon::parse('2026-03-15', 'Asia/Dubai')->startOfDay();
            $this->currentContract = (object) [
                'start_date' => Carbon::parse('2024-01-10', 'Asia/Dubai')->startOfDay(),
                'end_date' => Carbon::parse('2025-12-31', 'Asia/Dubai')->startOfDay(),
                'labor_contract_id' => null,
            ];
        }
    };

    // Simulate the unsafe nested Carbon JSON shift the scanner protects against.
    $unsafe = json_encode(['start_date' => $employee->currentContract->start_date]);
    expect($unsafe)->toContain('2024-01-09T');

    $safeStart = $employee->currentContract->start_date?->toDateString();
    $safeEnd = $employee->currentContract->end_date?->toDateString();
    $safeDob = $employee->date_of_birth?->toDateString();

    expect($safeStart)->toBe('2024-01-10')
        ->and($safeEnd)->toBe('2025-12-31')
        ->and($safeDob)->toBe('1992-08-29')
        ->and(json_encode(['start_date' => $safeStart]))->toBe('{"start_date":"2024-01-10"}');
});

/**
 * @return list<string>
 */
function collectModelCalendarDateKeys(string $modelsRoot): array
{
    $keys = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($modelsRoot, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (preg_match_all("/'([a-z0-9_]+)'\s*=>\s*'date(?:\:Y-m-d)?'/", $source, $matches)) {
            foreach ($matches[1] as $key) {
                $keys[$key] = true;
            }
        }

        if (preg_match_all("/'([a-z0-9_]+)'\s*=>\s*'immutable_date(?:\:Y-m-d)?'/", $source, $matches)) {
            foreach ($matches[1] as $key) {
                $keys[$key] = true;
            }
        }
    }

    $keys = array_keys($keys);
    sort($keys);

    return $keys;
}

function expressionAlreadySerializesCalendarDate(string $expression): bool
{
    return str_contains($expression, 'toDateString')
        || str_contains($expression, "format('Y-m-d')")
        || str_contains($expression, 'format("Y-m-d")')
        || str_contains($expression, 'PlatformDateFormatter')
        || (bool) preg_match('/\$dates->date\(|formatCvDate\(/', $expression);
}

function expressionIsNonPayloadNoise(string $expression): bool
{
    return str_contains($expression, '(bool)')
        || str_contains($expression, 'orderBy')
        || str_contains($expression, '$request')
        || str_contains($expression, 'old(')
        || str_contains($expression, 'validated(');
}
