<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->string('status_before_administrative_deletion', 32)->nullable()->after('cancellation_reason');
            $table->text('administrative_deletion_reason')->nullable()->after('status_before_administrative_deletion');
            $table->foreignId('administratively_deleted_by')
                ->nullable()
                ->after('administrative_deletion_reason')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('administratively_deleted_by');
            $table->dropColumn([
                'administrative_deletion_reason',
                'status_before_administrative_deletion',
            ]);
        });
    }
};
