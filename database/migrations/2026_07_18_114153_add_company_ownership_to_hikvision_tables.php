<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hikvision_settings', function (Blueprint $table) {
            $table->foreignId('company_id')
                ->nullable()
                ->after('id')
                ->constrained('companies')
                ->nullOnDelete();
            $table->uuid('public_id')->nullable()->after('company_id');
        });

        Schema::table('hikvision_devices', function (Blueprint $table) {
            $table->foreignId('company_id')
                ->nullable()
                ->after('id')
                ->constrained('companies')
                ->nullOnDelete();
        });

        Schema::table('hikvision_person_groups', function (Blueprint $table) {
            $table->foreignId('company_id')
                ->nullable()
                ->after('id')
                ->constrained('companies')
                ->nullOnDelete();
        });

        Schema::table('hikvision_persons', function (Blueprint $table) {
            $table->foreignId('company_id')
                ->nullable()
                ->after('id')
                ->constrained('companies')
                ->nullOnDelete();
        });

        Schema::table('hikvision_access_events', function (Blueprint $table) {
            $table->foreignId('company_id')
                ->nullable()
                ->after('id')
                ->constrained('companies')
                ->nullOnDelete();
            $table->foreignId('hikvision_person_id')
                ->nullable()
                ->after('company_id')
                ->constrained('hikvision_persons')
                ->nullOnDelete();
            $table->foreignId('hikvision_device_id')
                ->nullable()
                ->after('hikvision_person_id')
                ->constrained('hikvision_devices')
                ->nullOnDelete();
        });

        $this->backfillSettings();
        $this->backfillPersons();
        $this->backfillDevicesAndGroups();
        $this->backfillAccessEvents();
        $this->ensurePublicIds();

        Schema::table('hikvision_settings', function (Blueprint $table) {
            $table->unique('company_id');
            $table->unique('public_id');
        });

        $this->replaceUniqueIndex('hikvision_devices', 'hikvision_devices_serial_no_unique', ['serial_no'], ['company_id', 'serial_no'], 'hikvision_devices_company_serial_unique');
        $this->replaceUniqueIndex('hikvision_person_groups', 'hikvision_person_groups_group_id_unique', ['group_id'], ['company_id', 'group_id'], 'hikvision_person_groups_company_group_unique');
        $this->replaceUniqueIndex('hikvision_persons', 'hikvision_persons_person_id_unique', ['person_id'], ['company_id', 'person_id'], 'hikvision_persons_company_person_unique');
        $this->replaceUniqueIndex('hikvision_access_events', 'hv_access_events_unique', ['system_id', 'occurrence_time', 'msg_type'], ['company_id', 'system_id'], 'hikvision_access_events_company_system_unique');
    }

    public function down(): void
    {
        $this->replaceUniqueIndex('hikvision_access_events', 'hikvision_access_events_company_system_unique', ['company_id', 'system_id'], ['system_id', 'occurrence_time', 'msg_type'], 'hv_access_events_unique');
        $this->replaceUniqueIndex('hikvision_persons', 'hikvision_persons_company_person_unique', ['company_id', 'person_id'], ['person_id'], 'hikvision_persons_person_id_unique');
        $this->replaceUniqueIndex('hikvision_person_groups', 'hikvision_person_groups_company_group_unique', ['company_id', 'group_id'], ['group_id'], 'hikvision_person_groups_group_id_unique');
        $this->replaceUniqueIndex('hikvision_devices', 'hikvision_devices_company_serial_unique', ['company_id', 'serial_no'], ['serial_no'], 'hikvision_devices_serial_no_unique');

        Schema::table('hikvision_settings', function (Blueprint $table) {
            $table->dropUnique(['company_id']);
            $table->dropUnique(['public_id']);
        });

        Schema::table('hikvision_access_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hikvision_device_id');
            $table->dropConstrainedForeignId('hikvision_person_id');
            $table->dropConstrainedForeignId('company_id');
        });

        Schema::table('hikvision_persons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });

        Schema::table('hikvision_person_groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });

        Schema::table('hikvision_devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });

        Schema::table('hikvision_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn('public_id');
        });
    }

    private function backfillSettings(): void
    {
        $companyIds = DB::table('companies')->whereNull('deleted_at')->orderBy('id')->pluck('id');

        if ($companyIds->count() === 1) {
            DB::table('hikvision_settings')
                ->whereNull('company_id')
                ->update(['company_id' => $companyIds->first()]);
        }
    }

    private function backfillPersons(): void
    {
        $linked = DB::table('employees')
            ->select('hikvision_person_id', 'company_id')
            ->whereNotNull('hikvision_person_id')
            ->whereNull('deleted_at')
            ->get()
            ->groupBy('hikvision_person_id');

        foreach ($linked as $personId => $rows) {
            $companyIds = $rows->pluck('company_id')->unique()->values();

            if ($companyIds->count() !== 1) {
                continue;
            }

            DB::table('hikvision_persons')
                ->where('id', $personId)
                ->whereNull('company_id')
                ->update(['company_id' => $companyIds->first()]);
        }
    }

    private function backfillDevicesAndGroups(): void
    {
        $settingsCompanyId = DB::table('hikvision_settings')
            ->whereNotNull('company_id')
            ->value('company_id');

        if ($settingsCompanyId === null) {
            return;
        }

        $settingsCount = DB::table('hikvision_settings')->whereNotNull('company_id')->count();

        if ($settingsCount !== 1) {
            return;
        }

        DB::table('hikvision_devices')
            ->whereNull('company_id')
            ->update(['company_id' => $settingsCompanyId]);

        DB::table('hikvision_person_groups')
            ->whereNull('company_id')
            ->update(['company_id' => $settingsCompanyId]);
    }

    private function backfillAccessEvents(): void
    {
        $persons = DB::table('hikvision_persons')
            ->whereNotNull('company_id')
            ->whereNotNull('person_id')
            ->get(['id', 'company_id', 'person_id']);

        foreach ($persons as $person) {
            DB::table('hikvision_access_events')
                ->whereNull('company_id')
                ->where('person_hikvision_id', $person->person_id)
                ->update([
                    'company_id' => $person->company_id,
                    'hikvision_person_id' => $person->id,
                ]);
        }

        $devices = DB::table('hikvision_devices')
            ->whereNotNull('company_id')
            ->get(['id', 'company_id', 'hikvision_id', 'serial_no']);

        foreach ($devices as $device) {
            DB::table('hikvision_access_events')
                ->whereNull('company_id')
                ->where(function ($query) use ($device): void {
                    $query->where('device_id', $device->hikvision_id)
                        ->orWhere('device_id', $device->serial_no);
                })
                ->update([
                    'company_id' => $device->company_id,
                    'hikvision_device_id' => $device->id,
                ]);
        }

        $settingsCompanyId = DB::table('hikvision_settings')
            ->whereNotNull('company_id')
            ->value('company_id');
        $settingsCount = DB::table('hikvision_settings')->whereNotNull('company_id')->count();

        if ($settingsCompanyId !== null && $settingsCount === 1) {
            DB::table('hikvision_access_events')
                ->whereNull('company_id')
                ->where(function ($query): void {
                    $query->whereNotNull('hikvision_device_id')
                        ->orWhereNotNull('hikvision_person_id');
                })
                ->update(['company_id' => $settingsCompanyId]);
        }
    }

    private function ensurePublicIds(): void
    {
        $rows = DB::table('hikvision_settings')
            ->whereNull('public_id')
            ->orderBy('id')
            ->get(['id']);

        foreach ($rows as $row) {
            DB::table('hikvision_settings')
                ->where('id', $row->id)
                ->update(['public_id' => (string) Str::uuid()]);
        }
    }

    /**
     * @param  list<string>  $oldColumns
     * @param  list<string>  $newColumns
     */
    private function replaceUniqueIndex(
        string $table,
        string $oldIndex,
        array $oldColumns,
        array $newColumns,
        string $newIndex,
    ): void {
        Schema::table($table, function (Blueprint $blueprint) use ($oldIndex, $oldColumns, $newColumns, $newIndex): void {
            try {
                $blueprint->dropUnique($oldIndex);
            } catch (Throwable) {
                try {
                    $blueprint->dropUnique($oldColumns);
                } catch (Throwable) {
                    // Index may already be absent on fresh databases.
                }
            }

            $blueprint->unique($newColumns, $newIndex);
        });
    }
};
