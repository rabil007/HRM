<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crew_operational_alert_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies', indexName: 'crew_alert_rec_company_fk')
                ->cascadeOnDelete();
            $table->foreignId('crew_operational_alert_id')
                ->constrained('crew_operational_alerts', indexName: 'crew_alert_rec_alert_fk')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('users', indexName: 'crew_alert_rec_user_fk')
                ->cascadeOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['crew_operational_alert_id', 'user_id'],
                'crew_alert_recipients_alert_user_unique',
            );
            $table->index(
                ['company_id', 'user_id', 'read_at'],
                'crew_alert_rec_company_user_read_idx',
            );
            $table->index(
                ['company_id', 'crew_operational_alert_id'],
                'crew_alert_rec_company_alert_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crew_operational_alert_recipients');
    }
};
