<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crew_operational_alert_push_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('crew_operational_alert_id')
                ->constrained('crew_operational_alerts')
                ->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('notification_version');
            $table->string('status', 20);
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_category')->nullable();
            $table->timestamps();

            $table->unique(
                ['crew_operational_alert_id', 'user_id', 'notification_version'],
                'crew_alert_push_alert_user_version_unique',
            );
            $table->index(['company_id', 'user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crew_operational_alert_push_deliveries');
    }
};
