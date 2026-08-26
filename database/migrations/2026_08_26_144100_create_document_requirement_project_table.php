<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_requirement_project', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_requirement_id')->constrained('document_requirements')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();

            $table->unique(['document_requirement_id', 'project_id'], 'uq_doc_req_project');
            $table->index('project_id', 'idx_doc_req_project');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_requirement_project');
    }
};
