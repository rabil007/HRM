<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sssa_options', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('name', 'uq_sssa_options_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sssa_options');
    }
};
