<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_candidate_internal_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies', 'id', 'fk_cand_rem_comp')
                ->cascadeOnDelete();
            $table->foreignId('recruitment_candidate_id')
                ->constrained('recruitment_candidates', 'id', 'fk_cand_rem_cand')
                ->cascadeOnDelete();
            $table->string('schedule_type', 32);
            $table->string('schedule_key', 64);
            $table->string('milestone', 32);
            $table->date('target_date');
            $table->string('delivery_key', 64);
            $table->foreignId('user_id')
                ->constrained('users', 'id', 'fk_cand_rem_user')
                ->cascadeOnDelete();
            $table->string('status', 32)->default('pending');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->string('skip_reason', 100)->nullable();
            $table->string('title')->nullable();
            $table->text('summary')->nullable();
            $table->string('url', 500)->nullable();
            $table->timestamps();

            $table->unique(
                ['company_id', 'recruitment_candidate_id', 'schedule_type', 'schedule_key', 'milestone', 'user_id'],
                'cand_reminders_unique',
            );
            $table->index(
                ['company_id', 'status', 'schedule_type', 'target_date'],
                'cand_reminders_dispatch_idx',
            );
            $table->index(
                ['company_id', 'user_id', 'read_at'],
                'cand_reminders_user_read_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_candidate_internal_reminders');
    }
};
