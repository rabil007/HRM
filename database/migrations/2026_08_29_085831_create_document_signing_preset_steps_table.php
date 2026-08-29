<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_signing_preset_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies', indexName: 'doc_sign_step_comp_fk')
                ->cascadeOnDelete();
            $table->foreignId('document_signing_preset_id')
                ->constrained('document_signing_presets', indexName: 'doc_sign_step_preset_fk')
                ->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('recipient_role', 32);
            $table->string('target_type', 32);
            $table->foreignId('target_user_id')
                ->nullable()
                ->constrained('users', indexName: 'doc_sign_step_user_fk')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique(['document_signing_preset_id', 'sequence'], 'doc_sign_step_seq_unique');
            $table->index(['document_signing_preset_id', 'recipient_role'], 'doc_sign_step_role_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_signing_preset_steps');
    }
};
