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
        Schema::create('hikvision_access_events', function (Blueprint $table) {
            $table->id();
            $table->string('system_id');
            $table->string('msg_type');
            $table->timestamp('occurrence_time');
            $table->string('batch_id')->nullable();
            $table->string('device_id')->nullable();
            $table->string('device_name')->nullable();
            $table->string('resource_id')->nullable();
            $table->string('resource_name')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['system_id', 'occurrence_time', 'msg_type'], 'hv_access_events_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hikvision_access_events');
    }
};
