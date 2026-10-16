<?php

use App\Exports\AttendanceRecordsExport;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Services\Settings\SettingService;
use App\Support\Settings\PlatformDateFormatter;
use App\Support\Settings\SettingKey;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @return array{company: Company, employee: Employee}
 */
function makeAttendanceExportCompany(string $timezone, string $suffix): array
{
    $country = Country::query()->firstOrCreate(
        ['code' => 'A'.$suffix],
        ['name' => "Attendance Export {$suffix}", 'dial_code' => '+971', 'is_active' => true],
    );
    $currency = Currency::query()->firstOrCreate(
        ['code' => 'A'.$suffix],
        ['name' => "Attendance Export Currency {$suffix}", 'symbol' => 'A$', 'is_active' => true],
    );

    $company = Company::query()->create([
        'name' => "Attendance Export {$suffix}",
        'slug' => 'attendance-export-'.strtolower($suffix).'-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => $timezone,
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $branch = Branch::query()->create([
        'company_id' => $company->id,
        'name' => 'HQ',
        'code' => 'HQ',
        'status' => 'active',
        'is_headquarters' => true,
    ]);

    $employee = Employee::query()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'employee_no' => 'ATT-'.$suffix,
        'name' => "Attendance {$suffix}",
        'status' => 'active',
    ]);

    return compact('company', 'employee');
}

/**
 * Build a clock timestamp in the app storage timezone that represents the given UTC instant.
 */
function attendanceClockForUtcInstant(string $utcDateTime): Carbon
{
    return Carbon::parse($utcDateTime, 'UTC')->timezone(config('app.timezone'));
}

beforeEach(function () {
    app(SettingService::class)->set(SettingKey::DateFormat, 'Y-m-d');
    app(SettingService::class)->set(SettingKey::Timezone, 'UTC');
});

test('attendance export formats clock times in the company timezone', function () {
    ['employee' => $employee] = makeAttendanceExportCompany('Asia/Dubai', 'DXB');

    // UTC 2026-10-16 22:30 / 2026-10-17 06:30 → Dubai 02:30 / 10:30 on 17 Oct.
    $record = AttendanceRecord::factory()->forEmployee($employee)->create([
        'date' => '2026-10-16',
        'clock_in' => attendanceClockForUtcInstant('2026-10-16 22:30:00'),
        'clock_out' => attendanceClockForUtcInstant('2026-10-17 06:30:00'),
        'hours_worked' => 8,
        'status' => AttendanceRecord::STATUS_PRESENT,
    ]);

    $loaded = $record->fresh(['employee', 'company']);
    $export = new AttendanceRecordsExport(collect([$loaded]));
    $export->collection();
    $row = $export->map($loaded);

    expect($row[2])->toBe('2026-10-16')
        ->and($row[3])->toBe('2026-10-17 2:30 AM')
        ->and($row[4])->toBe('2026-10-17 10:30 AM')
        ->and(PlatformDateFormatter::make()->format())->toBe('Y-m-d');
});

test('attendance export uses america new york company timezone without shifting calendar date', function () {
    ['employee' => $employee] = makeAttendanceExportCompany('America/New_York', 'NYC');

    $record = AttendanceRecord::factory()->forEmployee($employee)->create([
        'date' => '2026-10-16',
        'clock_in' => attendanceClockForUtcInstant('2026-10-16 22:30:00'),
        'clock_out' => null,
        'hours_worked' => 0,
        'status' => AttendanceRecord::STATUS_PRESENT,
    ]);

    $loaded = $record->fresh(['employee', 'company']);
    $export = new AttendanceRecordsExport(collect([$loaded]));
    $export->collection();
    $row = $export->map($loaded);

    expect($row[2])->toBe('2026-10-16')
        ->and($row[3])->toBe('2026-10-16 6:30 PM')
        ->and($row[4])->toBeNull();
});

test('attendance export falls back to platform timezone when company timezone is invalid', function () {
    app(SettingService::class)->set(SettingKey::Timezone, 'Europe/London');
    ['company' => $company, 'employee' => $employee] = makeAttendanceExportCompany('Not/AZone', 'FBK');

    $record = AttendanceRecord::factory()->forEmployee($employee)->create([
        'date' => '2026-10-16',
        'clock_in' => attendanceClockForUtcInstant('2026-10-16 22:30:00'),
        'clock_out' => null,
        'status' => AttendanceRecord::STATUS_PRESENT,
    ]);

    DB::table('companies')->where('id', $company->id)->update(['timezone' => 'Not/AZone']);

    $loaded = $record->fresh(['employee', 'company']);
    $export = new AttendanceRecordsExport(collect([$loaded]));
    $export->collection();
    $row = $export->map($loaded);

    // Europe/London is BST in October (UTC+1) → 23:30 on 16 Oct.
    expect($row[3])->toBe('2026-10-16 11:30 PM');
});

test('attendance export maps each record with its own company timezone without n plus one', function () {
    ['employee' => $dubaiEmployee] = makeAttendanceExportCompany('Asia/Dubai', 'M1');
    ['employee' => $nyEmployee] = makeAttendanceExportCompany('America/New_York', 'M2');

    $dubaiRecord = AttendanceRecord::factory()->forEmployee($dubaiEmployee)->create([
        'date' => '2026-10-16',
        'clock_in' => attendanceClockForUtcInstant('2026-10-16 22:30:00'),
        'clock_out' => null,
        'status' => AttendanceRecord::STATUS_PRESENT,
    ]);
    $nyRecord = AttendanceRecord::factory()->forEmployee($nyEmployee)->create([
        'date' => '2026-10-16',
        'clock_in' => attendanceClockForUtcInstant('2026-10-16 22:30:00'),
        'clock_out' => null,
        'status' => AttendanceRecord::STATUS_PRESENT,
    ]);

    $records = collect([
        $dubaiRecord->fresh(['employee', 'company']),
        $nyRecord->fresh(['employee', 'company']),
    ]);

    $export = new AttendanceRecordsExport($records);
    $export->collection();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $dubaiRow = $export->map($records[0]);
    $nyRow = $export->map($records[1]);

    $companyQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'companies'));

    expect($companyQueries)->toBeEmpty()
        ->and($dubaiRow[3])->toBe('2026-10-17 2:30 AM')
        ->and($nyRow[3])->toBe('2026-10-16 6:30 PM');
});
