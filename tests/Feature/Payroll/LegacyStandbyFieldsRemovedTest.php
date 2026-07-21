<?php

use Illuminate\Support\Facades\Schema;

test('legacy generic standby columns no longer exist on crew timesheets', function () {
    expect(Schema::hasColumn('crew_timesheets', 'standby_from'))->toBeFalse()
        ->and(Schema::hasColumn('crew_timesheets', 'standby_to'))->toBeFalse()
        ->and(Schema::hasColumn('crew_timesheets', 'standby_days'))->toBeFalse()
        ->and(Schema::hasColumn('crew_timesheets', 'sign_on_standby_days'))->toBeTrue()
        ->and(Schema::hasColumn('crew_timesheets', 'onsite_days'))->toBeTrue()
        ->and(Schema::hasColumn('crew_timesheets', 'sign_off_standby_days'))->toBeTrue()
        ->and(Schema::hasColumn('crew_timesheets', 'unpaid_leave_days'))->toBeTrue();
});

test('runtime application code has no references to legacy generic standby fields', function () {
    $directories = [
        base_path('app'),
        base_path('resources/js'),
        base_path('routes'),
    ];

    $pattern = '/(?<![a-z_])standby_(from|to|days)(?![a-z_])/';
    $offenders = [];

    foreach ($directories as $directory) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $extension = $file->getExtension();

            if (! in_array($extension, ['php', 'ts', 'tsx'], true)) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            if (preg_match($pattern, $contents) === 1) {
                $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }
    }

    expect($offenders)->toBe([]);
});
