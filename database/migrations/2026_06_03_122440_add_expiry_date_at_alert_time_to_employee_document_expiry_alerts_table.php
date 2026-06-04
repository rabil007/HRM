<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_document_expiry_alerts')) {
            return;
        }

        if (Schema::hasColumn('employee_document_expiry_alerts', 'expiry_date_at_alert_time')) {
            return;
        }

        $this->dropForeignKeys();

        if ($this->hasIndex('employee_document_expiry_alerts_employee_document_id_unique')) {
            Schema::table('employee_document_expiry_alerts', function (Blueprint $table) {
                $table->dropUnique(['employee_document_id']);
            });
        }

        Schema::table('employee_document_expiry_alerts', function (Blueprint $table) {
            $table->date('expiry_date_at_alert_time')->nullable()->after('employee_document_id');
        });

        $alerts = DB::table('employee_document_expiry_alerts')
            ->whereNull('expiry_date_at_alert_time')
            ->get(['id', 'employee_document_id']);

        foreach ($alerts as $alert) {
            $expiryDate = DB::table('employee_documents')
                ->where('id', $alert->employee_document_id)
                ->value('expiry_date');

            if ($expiryDate === null) {
                DB::table('employee_document_expiry_alerts')->where('id', $alert->id)->delete();

                continue;
            }

            DB::table('employee_document_expiry_alerts')
                ->where('id', $alert->id)
                ->update(['expiry_date_at_alert_time' => $expiryDate]);
        }

        if (Schema::hasColumn('employee_document_expiry_alerts', 'sent_at')
            && ! Schema::hasColumn('employee_document_expiry_alerts', 'alerted_at')) {
            Schema::table('employee_document_expiry_alerts', function (Blueprint $table) {
                $table->timestamp('alerted_at')->nullable()->after('expiry_date_at_alert_time');
            });

            DB::table('employee_document_expiry_alerts')->update([
                'alerted_at' => DB::raw('sent_at'),
            ]);

            Schema::table('employee_document_expiry_alerts', function (Blueprint $table) {
                $table->dropColumn('sent_at');
            });
        }

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE employee_document_expiry_alerts MODIFY expiry_date_at_alert_time DATE NOT NULL');
        } else {
            DB::table('employee_document_expiry_alerts')
                ->whereNull('expiry_date_at_alert_time')
                ->delete();
        }

        if (! $this->hasIndex('employee_document_expiry_alerts_document_expiry_unique')) {
            Schema::table('employee_document_expiry_alerts', function (Blueprint $table) {
                $table->unique(
                    ['employee_document_id', 'expiry_date_at_alert_time'],
                    'employee_document_expiry_alerts_document_expiry_unique',
                );
            });
        }

        $this->restoreForeignKeys();
    }

    public function down(): void
    {
        if (! Schema::hasTable('employee_document_expiry_alerts')) {
            return;
        }

        if (! Schema::hasColumn('employee_document_expiry_alerts', 'expiry_date_at_alert_time')) {
            return;
        }

        $this->dropForeignKeys();

        if ($this->hasIndex('employee_document_expiry_alerts_document_expiry_unique')) {
            Schema::table('employee_document_expiry_alerts', function (Blueprint $table) {
                $table->dropUnique('employee_document_expiry_alerts_document_expiry_unique');
            });
        }

        Schema::table('employee_document_expiry_alerts', function (Blueprint $table) {
            $table->dropColumn('expiry_date_at_alert_time');
        });

        if (! $this->hasIndex('employee_document_expiry_alerts_employee_document_id_unique')) {
            Schema::table('employee_document_expiry_alerts', function (Blueprint $table) {
                $table->unique('employee_document_id');
            });
        }

        if (Schema::hasColumn('employee_document_expiry_alerts', 'alerted_at')
            && ! Schema::hasColumn('employee_document_expiry_alerts', 'sent_at')) {
            Schema::table('employee_document_expiry_alerts', function (Blueprint $table) {
                $table->timestamp('sent_at')->nullable();
            });

            DB::table('employee_document_expiry_alerts')->update([
                'sent_at' => DB::raw('alerted_at'),
            ]);

            Schema::table('employee_document_expiry_alerts', function (Blueprint $table) {
                $table->dropColumn('alerted_at');
            });
        }

        $this->restoreForeignKeys();
    }

    private function dropForeignKeys(): void
    {
        Schema::table('employee_document_expiry_alerts', function (Blueprint $table) {
            $table->dropForeign(['employee_document_id']);
            $table->dropForeign(['company_id']);
        });
    }

    private function restoreForeignKeys(): void
    {
        Schema::table('employee_document_expiry_alerts', function (Blueprint $table) {
            $table->foreign('employee_document_id')
                ->references('id')
                ->on('employee_documents')
                ->cascadeOnDelete();

            $table->foreign('company_id')
                ->references('id')
                ->on('companies')
                ->cascadeOnDelete();
        });
    }

    private function hasIndex(string $indexName): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            $rows = DB::select(
                'SHOW INDEX FROM employee_document_expiry_alerts WHERE Key_name = ?',
                [$indexName],
            );

            return $rows !== [];
        }

        if ($driver === 'sqlite') {
            $rows = DB::select(
                "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'employee_document_expiry_alerts' AND name = ?",
                [$indexName],
            );

            return $rows !== [];
        }

        return false;
    }
};
