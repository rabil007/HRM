<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @return list<string>
     */
    private function tables(): array
    {
        return [
            'attendance_records',
            'app_settings',
            'employee_document_expiry_alerts',
            'hikvision_access_events',
            'hikvision_devices',
            'hikvision_persons',
            'hikvision_person_groups',
            'hikvision_settings',
            'whatsapp_settings',
            'leave_balances',
        ];
    }

    public function up(): void
    {
        foreach ($this->tables() as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'deleted_at')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables() as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'deleted_at')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
