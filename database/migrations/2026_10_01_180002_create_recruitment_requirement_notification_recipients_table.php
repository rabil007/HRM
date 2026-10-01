<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_requirement_notification_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies', 'id', 'fk_req_notif_company')->cascadeOnDelete();
            $table->foreignId('recruitment_requirement_id')->constrained('recruitment_requirements', 'id', 'fk_req_notif_requirement')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users', 'id', 'fk_req_notif_user')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(
                ['recruitment_requirement_id', 'user_id'],
                'uq_recruitment_req_notif_req_user',
            );
            $table->index(
                ['company_id', 'recruitment_requirement_id'],
                'idx_recruitment_req_notif_company_req',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_requirement_notification_recipients');
    }
};
