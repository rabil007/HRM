<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crew_timesheets', function (Blueprint $table): void {
            $table->string('approval_status', 20)->default('draft')->after('source');
            $table->foreignId('submitted_by')->nullable()->after('approval_status')->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable()->after('submitted_by');
            $table->foreignId('approved_by')->nullable()->after('submitted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->foreignId('returned_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('returned_at')->nullable()->after('returned_by');
            $table->text('return_reason')->nullable()->after('returned_at');
        });

        DB::table('crew_timesheets')->update([
            'approval_status' => 'approved',
        ]);

        DB::table('crew_timesheets')
            ->where('source', 'crew_operations')
            ->whereNotNull('operational_approved_by')
            ->update([
                'approved_by' => DB::raw('operational_approved_by'),
                'approved_at' => DB::raw('operational_approved_at'),
            ]);
    }

    public function down(): void
    {
        Schema::table('crew_timesheets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('returned_by');
            $table->dropColumn([
                'approval_status',
                'submitted_at',
                'approved_at',
                'returned_at',
                'return_reason',
            ]);
        });
    }
};
