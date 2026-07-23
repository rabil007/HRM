<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcement_audiences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->string('audience_type', 32);
            $table->unsignedBigInteger('audience_id')->nullable();
            $table->timestamps();

            $table->index(['announcement_id', 'audience_type'], 'announcement_audiences_announcement_type_index');
            $table->index(['company_id', 'audience_type', 'audience_id'], 'announcement_audiences_company_type_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_audiences');
    }
};
