<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->string('slug', 220);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('slug', 'uq_document_types_slug');
            $table->index('is_active', 'idx_document_types_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_types');
    }
};
