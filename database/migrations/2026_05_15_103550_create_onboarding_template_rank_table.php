<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_template_rank', function (Blueprint $table) {
            $table->foreignId('onboarding_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rank_id')->constrained()->cascadeOnDelete();

            $table->primary(['onboarding_template_id', 'rank_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_template_rank');
    }
};
