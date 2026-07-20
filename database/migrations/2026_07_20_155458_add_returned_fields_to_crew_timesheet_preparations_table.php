<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crew_timesheet_preparations')) {
            return;
        }

        Schema::table('crew_timesheet_preparations', function (Blueprint $table): void {
            if (! Schema::hasColumn('crew_timesheet_preparations', 'returned_by')) {
                $table->foreignId('returned_by')
                    ->nullable()
                    ->after('approved_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('crew_timesheet_preparations', 'returned_at')) {
                $table->timestamp('returned_at')
                    ->nullable()
                    ->after('returned_by');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('crew_timesheet_preparations')) {
            return;
        }

        Schema::table('crew_timesheet_preparations', function (Blueprint $table): void {
            if (Schema::hasColumn('crew_timesheet_preparations', 'returned_by')) {
                $table->dropConstrainedForeignId('returned_by');
            }

            if (Schema::hasColumn('crew_timesheet_preparations', 'returned_at')) {
                $table->dropColumn('returned_at');
            }
        });
    }
};
