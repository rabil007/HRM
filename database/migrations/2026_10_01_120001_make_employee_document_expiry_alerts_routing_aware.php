<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('employee_document_expiry_alerts', 'notification_rule_id')) {
            Schema::table('employee_document_expiry_alerts', function (Blueprint $table) {
                $table->foreignId('notification_rule_id')
                    ->nullable()
                    ->after('company_id')
                    ->constrained('document_expiry_notification_rules')
                    ->nullOnDelete();
            });
        }

        // MySQL may be using the composite unique as the supporting index for
        // employee_document_id_foreign — add a dedicated index before dropping it.
        if (! $this->hasIndex('employee_document_expiry_alerts_employee_document_id_index')) {
            Schema::table('employee_document_expiry_alerts', function (Blueprint $table) {
                $table->index('employee_document_id', 'employee_document_expiry_alerts_employee_document_id_index');
            });
        }

        if ($this->hasIndex('employee_document_expiry_alerts_document_expiry_unique')) {
            Schema::table('employee_document_expiry_alerts', function (Blueprint $table) {
                $table->dropUnique('employee_document_expiry_alerts_document_expiry_unique');
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
    }

    public function down(): void
    {
        if ($this->hasIndex('employee_document_expiry_alerts_rule_document_expiry_unique')) {
            Schema::table('employee_document_expiry_alerts', function (Blueprint $table) {
                $table->dropUnique('employee_document_expiry_alerts_rule_document_expiry_unique');
            });
        }

        // Legacy uniqueness cannot coexist with multiple rule rows for the same document/expiry.
        // Keep surviving rows by deleting duplicates that share the old unique key before restore.
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement(<<<'SQL'
                DELETE FROM employee_document_expiry_alerts
                WHERE id NOT IN (
                    SELECT MIN(id)
                    FROM employee_document_expiry_alerts
                    GROUP BY employee_document_id, expiry_date_at_alert_time
                )
            SQL);
        } else {
            DB::statement(<<<'SQL'
                DELETE a FROM employee_document_expiry_alerts a
                INNER JOIN employee_document_expiry_alerts b
                    ON a.employee_document_id = b.employee_document_id
                    AND a.expiry_date_at_alert_time = b.expiry_date_at_alert_time
                    AND a.id > b.id
            SQL);
        }

        if (! $this->hasIndex('employee_document_expiry_alerts_document_expiry_unique')) {
            Schema::table('employee_document_expiry_alerts', function (Blueprint $table) {
                $table->unique(
                    ['employee_document_id', 'expiry_date_at_alert_time'],
                    'employee_document_expiry_alerts_document_expiry_unique',
                );
            });
        }

        if (Schema::hasColumn('employee_document_expiry_alerts', 'notification_rule_id')) {
            Schema::table('employee_document_expiry_alerts', function (Blueprint $table) {
                $table->dropConstrainedForeignId('notification_rule_id');
            });
        }

        if ($this->hasIndex('employee_document_expiry_alerts_employee_document_id_index')) {
            Schema::table('employee_document_expiry_alerts', function (Blueprint $table) {
                $table->dropIndex('employee_document_expiry_alerts_employee_document_id_index');
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
