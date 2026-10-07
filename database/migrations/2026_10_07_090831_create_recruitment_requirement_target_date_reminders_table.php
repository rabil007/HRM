<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_requirement_target_date_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('recruitment_requirement_id')->constrained('recruitment_requirements')->cascadeOnDelete();
            $table->date('target_date');
            $table->string('milestone', 32);
            $table->string('delivery_key', 64);
            $table->string('status', 32)->default('pending');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('skip_reason', 100)->nullable();
            $table->unsignedBigInteger('primary_recipient_user_id')->nullable();
            $table->json('cc_user_ids')->nullable();
            $table->timestamps();

            $table->unique(
                ['company_id', 'recruitment_requirement_id', 'target_date', 'milestone', 'delivery_key'],
                'req_target_date_reminders_unique',
            );
            $table->index(
                ['company_id', 'status', 'milestone', 'target_date'],
                'req_target_date_reminders_dispatch_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_requirement_target_date_reminders');
    }
};
