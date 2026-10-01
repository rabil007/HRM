<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rank_position_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('rank_id')->constrained('ranks')->restrictOnDelete();
            $table->foreignId('position_id')->constrained('positions')->restrictOnDelete();
            $table->string('match_type', 20);
            $table->timestamps();

            $table->unique(['company_id', 'rank_id'], 'uq_rank_position_mappings_company_rank');
            $table->index('position_id', 'idx_rank_position_mappings_position');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rank_position_mappings');
    }
};
