<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vessel_manning', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('vessel_id')->constrained('vessels')->restrictOnDelete();
            $table->foreignId('rank_id')->constrained('ranks')->restrictOnDelete();
            $table->unsignedInteger('required_count');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'vessel_id', 'rank_id'], 'uq_vessel_manning_company_vessel_rank');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vessel_manning');
    }
};
