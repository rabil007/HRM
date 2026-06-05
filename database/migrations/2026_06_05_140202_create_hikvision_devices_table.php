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
        Schema::create('hikvision_devices', function (Blueprint $table) {
            $table->id();
            $table->string('hikvision_id');
            $table->string('serial_no')->unique();
            $table->string('name')->nullable();
            $table->string('category')->nullable();
            $table->string('type')->nullable();
            $table->unsignedTinyInteger('online_status')->nullable();
            $table->json('raw_list_payload')->nullable();
            $table->json('raw_detail_payload')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hikvision_devices');
    }
};
