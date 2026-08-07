<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crew_operational_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('type', 64);
            $table->string('severity', 32);
            $table->string('status', 32)->default('active');
            $table->string('dedupe_key', 191);
            $table->string('title');
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('detected_at');
            $table->timestamp('last_detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'dedupe_key']);
            $table->index(['company_id', 'status', 'type']);
            $table->index(['company_id', 'last_detected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crew_operational_alerts');
    }
};
