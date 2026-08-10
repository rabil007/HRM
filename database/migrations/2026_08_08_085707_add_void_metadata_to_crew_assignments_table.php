<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crew_assignments', function (Blueprint $table) {
            $table->timestamp('voided_at')->nullable()->after('deleted_at');
            $table->foreignId('voided_by')
                ->nullable()
                ->after('voided_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->text('void_reason')->nullable()->after('voided_by');

            $table->index(['company_id', 'voided_at'], 'crew_assign_co_voided_idx');
        });
    }

    public function down(): void
    {
        Schema::table('crew_assignments', function (Blueprint $table) {
            $table->dropIndex('crew_assign_co_voided_idx');
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['voided_at', 'void_reason']);
        });
    }
};
