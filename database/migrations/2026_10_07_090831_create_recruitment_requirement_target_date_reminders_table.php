<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A prior deploy failed after MySQL auto-named FKs exceeded the 64-char limit,
        // which can leave a partial empty table. Safe to recreate: no successful migrate ran.
        Schema::dropIfExists('recruitment_requirement_target_date_reminders');

        Schema::create('recruitment_requirement_target_date_reminders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('recruitment_requirement_id');
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

            $table->foreign('company_id', 'req_td_reminders_company_fk')
                ->references('id')
                ->on('companies')
                ->cascadeOnDelete();
            $table->foreign('recruitment_requirement_id', 'req_td_reminders_requirement_fk')
                ->references('id')
                ->on('recruitment_requirements')
                ->cascadeOnDelete();

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
