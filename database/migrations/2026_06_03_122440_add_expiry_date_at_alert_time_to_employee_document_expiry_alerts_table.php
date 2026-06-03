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

        Schema::table('employee_document_expiry_alerts', function (Blueprint $table) {
            $table->dropUnique(['employee_document_id']);
        });

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

        Schema::table('employee_document_expiry_alerts', function (Blueprint $table) {
            $table->unique(
                ['employee_document_id', 'expiry_date_at_alert_time'],
                'employee_document_expiry_alerts_document_expiry_unique',
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('employee_document_expiry_alerts')) {
            return;
        }

        Schema::table('employee_document_expiry_alerts', function (Blueprint $table) {
            $table->dropUnique('employee_document_expiry_alerts_document_expiry_unique');
            $table->dropColumn('expiry_date_at_alert_time');
            $table->unique('employee_document_id');
        });

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
    }
};
