<?php

namespace App\Console\Commands;

use App\Models\AttendanceRecord;
use App\Models\Company;
use App\Models\Employee;
use App\Models\HikvisionAccessEvent;
use App\Models\HikvisionPerson;
use App\Support\Attendance\SyncAttendanceRecordsFromHikvision;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SeedAttendanceHikvisionDemoCommand extends Command
{
    protected $signature = 'attendance:seed-hikvision-demo
                            {--company= : Company ID (defaults to Herd OMS)}
                            {--date=2026-06-12 : Attendance date to simulate}
                            {--sync : Run attendance sync after seeding}';

    protected $description = 'Seed linked employees, Hikvision persons, and access events for local checkout sync testing';

    public function handle(SyncAttendanceRecordsFromHikvision $sync): int
    {
        $date = (string) $this->option('date');
        $company = $this->resolveCompany();

        if ($company === null) {
            $this->error('No company found. Run database seeders first.');

            return self::FAILURE;
        }

        $this->info("Seeding Hikvision attendance demo for {$company->name} on {$date}...");

        $people = $this->demoPeople();

        foreach ($people as $person) {
            $this->seedPersonWithEvents($company, $date, $person);
        }

        if ($this->option('sync')) {
            $synced = $sync->syncCompany(
                (int) $company->id,
                Carbon::parse("{$date} 00:00:00"),
                Carbon::parse("{$date} 23:59:59"),
            );

            $this->info("Synced {$synced} attendance day row(s).");
        }

        $this->table(
            ['Employee', 'Linked', 'Clock in', 'Clock out', 'Source', 'Status'],
            AttendanceRecord::query()
                ->with('employee:id,name')
                ->where('company_id', $company->id)
                ->whereDate('date', $date)
                ->whereHas('employee', fn ($query) => $query->where('employee_no', 'like', 'DEMO-%'))
                ->orderBy('employee_id')
                ->get()
                ->map(fn (AttendanceRecord $record): array => [
                    $record->employee?->name ?? '—',
                    'yes',
                    $record->clock_in?->format('Y-m-d H:i') ?? '—',
                    $record->clock_out?->format('Y-m-d H:i') ?? '—',
                    $record->source,
                    $record->status,
                ])
                ->all(),
        );

        $this->newLine();
        $this->line('Open locally:');
        $this->line("  /hikvision/access-events?date_from={$date}&date_to={$date}&attendance_status=checkOut");
        $this->line("  /attendance/records?date_from={$date}&date_to={$date}");
        $this->line('Re-run sync: php artisan attendance:seed-hikvision-demo --sync');

        return self::SUCCESS;
    }

    private function resolveCompany(): ?Company
    {
        $companyId = $this->option('company');

        if ($companyId !== null && $companyId !== '') {
            return Company::query()->find((int) $companyId);
        }

        return Company::query()
            ->where('slug', 'herd-oms')
            ->orWhere('name', 'Herd OMS')
            ->first();
    }

    /**
     * @return list<array{name: string, person_id: string, check_in: string, check_out: string, checkout_source: string}>
     */
    private function demoPeople(): array
    {
        return [
            ['name' => 'Mohamed Abdalla', 'person_id' => 'demo-mohamed-abdalla', 'check_in' => '08:10:00', 'check_out' => '19:40:00', 'checkout_source' => HikvisionAccessEvent::TRANSACTION_MOBILE_APP],
            ['name' => 'Adham', 'person_id' => 'demo-adham', 'check_in' => '09:00:00', 'check_out' => '18:13:00', 'checkout_source' => HikvisionAccessEvent::TRANSACTION_MOBILE_APP],
            ['name' => 'Mohamed Hassan', 'person_id' => 'demo-mohamed-hassan', 'check_in' => '08:30:00', 'check_out' => '18:09:00', 'checkout_source' => HikvisionAccessEvent::TRANSACTION_DEVICE],
            ['name' => 'Mohammed Rabil', 'person_id' => 'demo-mohammed-rabil', 'check_in' => '08:00:00', 'check_out' => '17:39:00', 'checkout_source' => HikvisionAccessEvent::TRANSACTION_DEVICE],
            ['name' => 'Syam', 'person_id' => 'demo-syam', 'check_in' => '08:45:00', 'check_out' => '17:35:00', 'checkout_source' => HikvisionAccessEvent::TRANSACTION_DEVICE],
            ['name' => 'Khaled', 'person_id' => 'demo-khaled', 'check_in' => '09:15:00', 'check_out' => '17:31:00', 'checkout_source' => HikvisionAccessEvent::TRANSACTION_MOBILE_APP],
            ['name' => 'kiron', 'person_id' => 'demo-kiron', 'check_in' => '08:20:00', 'check_out' => '16:15:00', 'checkout_source' => HikvisionAccessEvent::TRANSACTION_MOBILE_APP],
        ];
    }

    /**
     * @param  array{name: string, person_id: string, check_in: string, check_out: string, checkout_source: string}  $person
     */
    private function seedPersonWithEvents(Company $company, string $date, array $person): void
    {
        $displayName = $person['name'];

        $hikvisionPerson = HikvisionPerson::query()->updateOrCreate(
            ['person_id' => $person['person_id']],
            [
                'full_name' => $person['name'],
                'first_name' => $person['name'],
                'synced_at' => now(),
            ],
        );

        $employee = Employee::query()->updateOrCreate(
            [
                'company_id' => $company->id,
                'employee_no' => 'DEMO-'.Str::upper(Str::substr($person['person_id'], -6)),
            ],
            [
                'name' => $displayName,
                'status' => 'active',
                'hikvision_person_id' => $hikvisionPerson->id,
            ],
        );

        $employee->update(['hikvision_person_id' => $hikvisionPerson->id]);

        $this->upsertAccessEvent(
            systemId: "demo:{$person['person_id']}:checkin",
            date: $date,
            time: $person['check_in'],
            personName: $person['name'],
            attendanceStatus: HikvisionAccessEvent::ATTENDANCE_CHECK_IN,
            transactionSource: $person['checkout_source'] === HikvisionAccessEvent::TRANSACTION_MOBILE_APP
                ? HikvisionAccessEvent::TRANSACTION_MOBILE_APP
                : HikvisionAccessEvent::TRANSACTION_DEVICE,
            deviceName: $person['checkout_source'] === HikvisionAccessEvent::TRANSACTION_MOBILE_APP ? 'Mobile App' : 'OMS-Door',
        );

        $this->upsertAccessEvent(
            systemId: "demo:{$person['person_id']}:checkout",
            date: $date,
            time: $person['check_out'],
            personName: $person['name'],
            attendanceStatus: HikvisionAccessEvent::ATTENDANCE_CHECK_OUT,
            transactionSource: $person['checkout_source'],
            deviceName: $person['checkout_source'] === HikvisionAccessEvent::TRANSACTION_MOBILE_APP ? 'Mobile App' : 'OMS-Door',
        );

        $this->line("  • {$displayName} linked to {$person['person_id']}");
    }

    private function upsertAccessEvent(
        string $systemId,
        string $date,
        string $time,
        string $personName,
        string $attendanceStatus,
        string $transactionSource,
        string $deviceName,
    ): void {
        $occurrenceTime = Carbon::parse("{$date} {$time}");

        HikvisionAccessEvent::query()->updateOrCreate(
            [
                'system_id' => $systemId,
                'occurrence_time' => $occurrenceTime,
            ],
            [
                'msg_type' => $transactionSource === HikvisionAccessEvent::TRANSACTION_MOBILE_APP
                    ? 'attendance/totaltimecard'
                    : 'acs/5/38',
                'person_name' => $personName,
                'device_name' => $deviceName,
                'attendance_status' => $attendanceStatus,
                'event_source' => $transactionSource === HikvisionAccessEvent::TRANSACTION_MOBILE_APP
                    ? HikvisionAccessEvent::EVENT_SOURCE_ATTENDANCE_API
                    : HikvisionAccessEvent::EVENT_SOURCE_ACS_ISAPI,
                'transaction_source' => $transactionSource,
                'fetched_at' => now(),
            ],
        );
    }
}
