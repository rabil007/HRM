<?php

/**
 * Guards Inertia/JSON payloads against timezone-shifted calendar dates.
 *
 * Eloquent `date` casts serialize as UTC ISO instants when passed as Carbon.
 * With APP_TIMEZONE=Asia/Dubai that becomes the previous calendar day in JSON.
 */
test('inertia resources presenters and page data serialize calendar dates with toDateString', function () {
    $root = dirname(__DIR__, 3).'/app';
    $keys = [
        'date_of_birth',
        'hire_date',
        'termination_date',
        'start_date',
        'end_date',
        'expiry_date',
        'issue_date',
        'effective_from',
        'effective_to',
        'opening_balance_as_of',
        'payment_date',
        'work_date',
        'cutoff_date',
        'effective_cutoff_date',
        'from_date',
        'to_date',
        'date_from',
        'date_to',
        'check_in_date',
        'check_out_date',
        'first_dose_date',
        'second_dose_date',
        'booster_dose_date',
        'expected_joining_date',
        'actual_joining_date',
        'proposed_joining_date',
        'offer_date',
        'request_received_date',
        'required_by_date',
        'old_deadline',
        'requested_deadline',
        'target_date',
        'planned_arrival_date',
        'planned_join_date',
        'planned_leave_date',
        'sign_on_standby_from',
        'sign_on_standby_to',
        'onsite_from',
        'onsite_to',
        'sign_off_standby_from',
        'sign_off_standby_to',
        'date',
    ];

    $hits = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        if (! preg_match('/(Resource|Presenter|PageData)\.php$/', $file->getFilename())) {
            continue;
        }

        $path = $file->getPathname();
        $relative = str_replace('\\', '/', substr($path, strlen(dirname($root)) + 1));

        foreach (file($path) as $lineNumber => $line) {
            if (! preg_match("/'([^']+)'\s*=>\s*(.+),?\s*$/", trim($line), $matches)) {
                continue;
            }

            $key = $matches[1];
            $expression = rtrim($matches[2], ',');

            if (! in_array($key, $keys, true)) {
                continue;
            }

            if (
                str_contains($expression, 'toDateString')
                || str_contains($expression, "format('Y-m-d')")
                || str_contains($expression, 'format("Y-m-d")')
                || str_contains($expression, 'PlatformDateFormatter')
                || preg_match('/\$dates->date\(|->date\(/', $expression)
            ) {
                continue;
            }

            if (! preg_match('/\$[A-Za-z0-9_]+(?:\?->|->)[A-Za-z0-9_]+/', $expression)) {
                continue;
            }

            if (
                str_contains($expression, '(bool)')
                || str_contains($expression, 'orderBy')
                || str_contains($expression, '$request')
            ) {
                continue;
            }

            $hits[] = sprintf('%s:%d %s => %s', $relative, $lineNumber + 1, $key, $expression);
        }
    }

    expect($hits)->toBeEmpty(
        "Calendar date props must use toDateString()/Y-m-d before Inertia JSON:\n".implode("\n", $hits)
    );
});
