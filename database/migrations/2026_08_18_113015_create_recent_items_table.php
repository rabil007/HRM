<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recent_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('company_id');
            $table->string('record_type', 32);
            $table->unsignedBigInteger('record_id');
            $table->timestamp('last_viewed_at');
            $table->timestamps();

            $table->unique(['user_id', 'company_id', 'record_type', 'record_id'], 'recent_items_user_company_record_unique');
            $table->index(['user_id', 'company_id', 'last_viewed_at'], 'recent_items_user_company_viewed_index');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recent_items');
    }
};
