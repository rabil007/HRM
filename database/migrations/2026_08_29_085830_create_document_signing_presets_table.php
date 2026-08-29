<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_signing_presets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies', indexName: 'doc_sign_preset_comp_fk')
                ->cascadeOnDelete();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('status', 32)->default('active');
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users', indexName: 'doc_sign_preset_by_fk')
                ->nullOnDelete();
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users', indexName: 'doc_sign_preset_upd_fk')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'name'], 'uq_doc_sign_preset_company_name');
            $table->index(['company_id', 'status'], 'doc_sign_preset_comp_stat_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_signing_presets');
    }
};
