<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hikvision_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();
            $table->date('target_date');
            $table->string('status', 32)->default('completed');
            $table->string('fetch_origin', 64)->nullable();
            $table->unsignedInteger('events_fetched_count')->default(0);
            $table->unsignedInteger('device_events_count')->default(0);
            $table->unsignedInteger('mobile_events_count')->default(0);
            $table->unsignedInteger('attendance_synced_count')->default(0);
            $table->dateTime('reconciled_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'target_date'], 'hv_reconciliations_company_target_unique');
            $table->index(['company_id', 'status', 'target_date'], 'hv_reconciliations_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hikvision_reconciliations');
    }
};
