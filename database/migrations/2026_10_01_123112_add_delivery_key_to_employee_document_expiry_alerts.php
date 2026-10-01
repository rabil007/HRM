<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('employee_document_expiry_alerts', 'delivery_key')) {
            Schema::table('employee_document_expiry_alerts', function (Blueprint $table) {
                $table->string('delivery_key', 64)
                    ->default('')
                    ->after('expiry_date_at_alert_time');
            });
        }

        // Existing rows predate cohort-aware delivery. "legacy" suppresses every
        // cohort for that document+expiry under the attached rule.
        DB::table('employee_document_expiry_alerts')
            ->where('delivery_key', '')
            ->update(['delivery_key' => 'legacy']);

        if ($this->hasIndex('employee_document_expiry_alerts_rule_document_expiry_unique')) {
            Schema::table('employee_document_expiry_alerts', function (Blueprint $table) {
                $table->dropUnique('employee_document_expiry_alerts_rule_document_expiry_unique');
            });
        }

        if (! $this->hasIndex('employee_document_expiry_alerts_rule_doc_expiry_delivery_unique')) {
            Schema::table('employee_document_expiry_alerts', function (Blueprint $table) {
                $table->unique(
                    ['notification_rule_id', 'employee_document_id', 'expiry_date_at_alert_time', 'delivery_key'],
                    'employee_document_expiry_alerts_rule_doc_expiry_delivery_unique',
                );
            });
        }
    }

    public function down(): void
    {
        if ($this->hasIndex('employee_document_expiry_alerts_rule_doc_expiry_delivery_unique')) {
            Schema::table('employee_document_expiry_alerts', function (Blueprint $table) {
                $table->dropUnique('employee_document_expiry_alerts_rule_doc_expiry_delivery_unique');
            });
        }

        if (! $this->hasIndex('employee_document_expiry_alerts_rule_document_expiry_unique')) {
            Schema::table('employee_document_expiry_alerts', function (Blueprint $table) {
                $table->unique(
                    ['notification_rule_id', 'employee_document_id', 'expiry_date_at_alert_time'],
                    'employee_document_expiry_alerts_rule_document_expiry_unique',
                );
            });
        }

        if (Schema::hasColumn('employee_document_expiry_alerts', 'delivery_key')) {
            Schema::table('employee_document_expiry_alerts', function (Blueprint $table) {
                $table->dropColumn('delivery_key');
            });
        }
    }

    private function hasIndex(string $indexName): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = $connection->select(
                "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'employee_document_expiry_alerts' AND name = ?",
                [$indexName],
            );

            return $indexes !== [];
        }

        $indexes = $connection->select(
            'SHOW INDEX FROM employee_document_expiry_alerts WHERE Key_name = ?',
            [$indexName],
        );

        return $indexes !== [];
    }
};
